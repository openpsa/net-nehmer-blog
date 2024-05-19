<?php
/**
 * @package net.nehmer.blog
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

use midcom\datamanager\datamanager;

/**
 * Blog Index handler page handler
 *
 * Shows the configured number of postings with their abstracts.
 *
 * @package net.nehmer.blog
 */
class net_nehmer_blog_handler_index extends midcom_baseclasses_components_handler
{
    use net_nehmer_blog_handler;

    /**
     * @var midcom_db_article[]
     */
    private array $_articles;

    private string $category;

    /**
     * Shows the autoindex list. Nothing to do in the handle phase except setting last modified
     * dates.
     */
    public function _handler_index(string $handler_id, array &$data, ?int $results_per_page = null, ?string $category = null)
    {
        if ($handler_id == 'ajax-latest') {
            midcom::get()->skip_page_style = true;
        }

        $data['datamanager'] = new datamanager($data['schemadb']);
        $qb = new org_openpsa_qbpager(midcom_db_article::class, 'net_nehmer_blog_index');
        $data['qb'] = $qb;
        $this->article_qb_constraints($qb);

        // Set default page title
        $data['page_title'] = $this->_topic->extra;

        // Filter by categories
        if ($category) {
            $this->category = trim(strip_tags($category));

            $this->_process_category_constraint($qb);
        }

        $qb->add_order('metadata.published', 'DESC');

        $qb->results_per_page = $results_per_page ?? $this->_config->get('index_entries');

        $this->_articles = $qb->execute();

        midcom::get()->metadata->set_request_metadata($this->get_last_modified(), $this->_topic->guid);

        if ($qb->get_current_page() > 1) {
            $this->add_breadcrumb(
                midcom_core_context::get()->get_key(MIDCOM_CONTEXT_ANCHORPREFIX),
                sprintf($this->_i18n->get_string('page %s', 'org.openpsa.qbpager'), $qb->get_current_page())
            );
        }
    }

    private function _process_category_constraint(org_openpsa_qbpager $qb)
    {
        if (   !in_array($this->category, $this->_request_data['categories'])
            && !$this->_config->get('categories_custom_enable')) {
            throw new midcom_error('Custom categories are not allowed');
            // TODO: Check here if there are actually items in this cat?
        }

        $this->apply_category_constraint($qb, $this->category);

        // Add category to title
        $this->_request_data['page_title'] = sprintf($this->_l10n->get('%s category %s'), $this->_topic->extra, $this->category);
        midcom::get()->head->set_pagetitle($this->_request_data['page_title']);

        // Activate correct leaf
        if (   $this->_config->get('show_navigation_pseudo_leaves')
            && in_array($this->category, $this->_request_data['categories'])) {
            $this->set_active_leaf($this->_topic->id . '_CAT_' . $this->category);
        }

        // Add RSS feed to headers
        if ($this->_config->get('rss_enable')) {
            midcom::get()->head->add_link_head([
                'rel'   => 'alternate',
                'type'  => 'application/rss+xml',
                'title' => $this->_l10n->get('rss 2.0 feed') . ": {$this->category}",
                'href' => $this->router->generate('feed-category-rss2', ['category' => $this->category])
            ]);
        }
    }

    /**
     * Displays the index page
     */
    public function _show_index(string $handler_id, array &$data)
    {
        $data['index_fulltext'] = $this->_config->get('index_fulltext');

        if ($this->_config->get('ajax_comments_enable')) {
            if ($node = net_nehmer_comments_interface::get_node($this->_topic, $this->_config->get('comments_topic'))) {
                $data['base_ajax_comments_url'] = $node[MIDCOM_NAV_RELATIVEURL] . "comment/";
            }
        }

        midcom_show_style('index-start');

        if ($this->_articles) {
            $prefix = midcom_core_context::get()->get_key(MIDCOM_CONTEXT_ANCHORPREFIX);
            $total_count = count($this->_articles);
            $data['article_count'] = $total_count;
            foreach ($this->_articles as $article_counter => $article) {
                try {
                    $data['datamanager']->set_storage($article);
                } catch (midcom_error $e) {
                    $e->log();
                    continue;
                }

                $data['article'] = $article;
                $data['article_counter'] = $article_counter;

                $data['local_view_url'] = $prefix . $this->get_url($article);
                $data['view_url'] = $this->get_url($article, true);
                if (!preg_match('/^http(s):\/\//', $data['view_url'])) {
                    $data['view_url'] = $prefix . $data['view_url'];
                }
                $data['linked'] = ($article->topic !== $this->_topic->id);
                if ($data['linked']) {
                    $nap = new midcom_helper_nav();
                    $data['node'] = $nap->get_node($article->topic);
                }

                midcom_show_style('index-item');
            }
        } else {
            midcom_show_style('index-empty');
        }

        midcom_show_style('index-end');
    }
}
