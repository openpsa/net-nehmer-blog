<?php
/**
 * @package net.nehmer.blog
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

use midcom\datamanager\datamanager;
use midcom\datamanager\schemadb;

/**
 * Blog site interface class
 *
 * @package net.nehmer.blog
 */
class net_nehmer_blog_viewer extends midcom_baseclasses_components_viewer
{
    /**
     * Initialize the request switch and the content topic.
     */
    public function _on_initialize()
    {
        if ($this->_config->get('rss_subscription_enable')) {
            net_nemein_rss_manage::register_plugin($this);
        }
    }

    /**
     * Adds the RSS Feed LINK head elements.
     */
    private function _add_link_head()
    {
        if ($this->_config->get('rss_enable')) {
            midcom::get()->head->add_link_head([
                'rel'   => 'alternate',
                'type'  => 'application/rss+xml',
                'title' => $this->_l10n->get('rss 2.0 feed'),
                'href'  => midcom::get()->get_host_name() . midcom_core_context::get()->get_key(MIDCOM_CONTEXT_ANCHORPREFIX) . 'rss.xml',
            ]);
            midcom::get()->head->add_link_head([
                'rel'   => 'alternate',
                'type'  => 'application/atom+xml',
                'title' => $this->_l10n->get('atom feed'),
                'href'  => midcom::get()->get_host_name() . midcom_core_context::get()->get_key(MIDCOM_CONTEXT_ANCHORPREFIX) . 'atom.xml',
            ]);
        }
    }

    /**
     * Populates the node toolbar depending on the user's rights.
     */
    private function _populate_node_toolbar()
    {
        $buttons = [];
        $workflow = $this->get_workflow('datamanager');
        if ($this->_topic->can_do('midgard:create')) {
            foreach ($this->_request_data['schemadb']->all() as $name => $schema) {
                $buttons[] = $workflow->get_button($this->router->generate('create', ['schema_name' => $name]), [
                    MIDCOM_TOOLBAR_LABEL => sprintf(
                        $this->_l10n_midcom->get('create %s'),
                        $this->_l10n->get($schema->get('description'))
                    ),
                    MIDCOM_TOOLBAR_GLYPHICON => 'file-o',
                    MIDCOM_TOOLBAR_ACCESSKEY => 'n',
                ]);
            }
        }

        if ($this->_config->get('rss_subscription_enable')) {
            net_nemein_rss_manage::add_toolbar_buttons($this->_node_toolbar, $this->_topic->can_do('midgard:create'));
        }

        if (   $this->_topic->can_do('midgard:update')
            && $this->_topic->can_do('midcom:component_config')) {
            $buttons[] = $workflow->get_button('config/', [
                MIDCOM_TOOLBAR_LABEL => $this->_l10n_midcom->get('component configuration'),
                MIDCOM_TOOLBAR_HELPTEXT => $this->_l10n_midcom->get('component configuration helptext'),
                MIDCOM_TOOLBAR_GLYPHICON => 'wrench',
            ]);
        }
        $this->_node_toolbar->add_items($buttons);
    }

    public function _on_handle($handler, array $args)
    {
        $this->_request_data['schemadb'] = schemadb::from_path($this->_config->get('schemadb'));
        $this->_add_categories();

        $this->_add_link_head();
        $this->_populate_node_toolbar();
    }

    /**
     * Populate the categories configured for the topic into the schemas
     */
    private function _add_categories()
    {
        $this->_request_data['categories'] = [];
        if ($this->_config->get('categories') != '') {
            $this->_request_data['categories'] = explode(',', $this->_config->get('categories'));

            foreach ($this->_request_data['schemadb']->all() as $schema) {
                if (   $schema->has_field('categories')
                    && $schema->get_field('categories')['type'] == 'select') {
                    // TODO: Merge schema local and component config categories?
                    $options = array_combine($this->_request_data['categories'], $this->_request_data['categories']);
                    $schema->get_field('categories')['type_config']['options'] = $options;
                }
            }
        }
    }

    /**
     * Indexes an article.
     *
     * @param midcom_db_topic|midcom_core_dbaproxy $topic The topic which we are bound to. If this is not an object, the code
     *     tries to load a new topic instance from the database identified by this parameter.
     */
    public static function index(datamanager $dm, midcom_services_indexer $indexer, $topic)
    {
        $config = new midcom_helper_configuration($topic, 'net.nehmer.blog');

        if ($config->get('disable_indexing')) {
            return;
        }

        $nav = new midcom_helper_nav();
        $node = $nav->get_node($topic->id);

        $document = $indexer->new_document($dm);
        $document->topic_guid = $topic->guid;
        $document->component = $topic->component;
        $document->topic_url = $node[MIDCOM_NAV_FULLURL];
        $indexer->index($document);
    }
}
