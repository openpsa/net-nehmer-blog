<?php
/**
 * @package net.nehmer.blog
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

use midcom\datamanager\datamanager;

/**
 * Blog Archive pages handler
 *
 * Shows the various archive views.
 *
 * @package net.nehmer.blog
 */
class net_nehmer_blog_handler_archive extends midcom_baseclasses_components_handler
{
    use net_nehmer_blog_handler;

    /**
     * @var midcom_db_article[]
     */
    private array $_articles;

    private DateTime $_start;

    private DateTime $_end;

    /**
     * Shows the archive welcome page: A listing of years/months along with total post counts
     * and similar stuff.
     *
     * The handler computes all necessary data and populates the request array accordingly.
     */
    public function _handler_welcome()
    {
        $this->_compute_welcome_data();

        if ($this->_config->get('archive_in_navigation')) {
            $this->set_active_leaf($this->_topic->id . '_ARCHIVE');
        }

        midcom::get()->head->set_pagetitle("{$this->_topic->extra}: " . $this->_l10n->get('archive'));

        midcom::get()->metadata->set_request_metadata($this->get_last_modified(), $this->_topic->guid);
    }

    /**
     * Loads the first posting time from the DB. This is the base for all operations on the
     * resultset.
     *
     * This is done under sudo if possible, to avoid problems arising if the first posting
     * is hidden. This keeps up performance, as an execute_unchecked() can be made in this case.
     * If sudo cannot be acquired, the system falls back to execute().
     */
    private function _compute_welcome_first_post() : ?DateTime
    {
        $qb = midcom_db_article::new_query_builder();
        $this->article_qb_constraints($qb);
        $qb->add_constraint('metadata.published', '>', '1970-01-02 23:59:59');

        $qb->add_order('metadata.published');
        $qb->set_limit(1);

        if (midcom::get()->auth->request_sudo($this->_component)) {
            $result = $qb->execute_unchecked();
            midcom::get()->auth->drop_sudo();
        } else {
            $result = $qb->execute();
        }

        if (!empty($result)) {
            return new DateTime('@' . $result[0]->metadata->published);
        }
        return null;
    }

    /**
     * Computes the number of postings in a given timeframe.
     *
     * @param DateTime $start Start of the timeframe (inclusive)
     * @param DateTime $end End of the timeframe (exclusive)
     */
    private function _compute_welcome_posting_count(DateTime $start, DateTime $end) : int
    {
        $qb = midcom_db_article::new_query_builder();

        $qb->add_constraint('metadata.published', '>=', $start->format('Y-m-d H:i:s'));
        $qb->add_constraint('metadata.published', '<', $end->format('Y-m-d H:i:s'));
        $this->article_qb_constraints($qb);

        return $qb->count();
    }

    /**
     * Computes the data necessary for the welcome screen. Automatically put into the request
     * data array.
     */
    private function _compute_welcome_data()
    {
        // First step of request data: Overall info
        $total_count = 0;
        $year_data = [];
        $first_post = $this->_compute_welcome_first_post();
        $this->_request_data['first_post'] = $first_post;
        $this->_request_data['total_count'] =& $total_count;
        $this->_request_data['year_data'] =& $year_data;
        if (!$first_post) {
            return;
        }

        // Second step of request data: Years and months.
        $now = new DateTime();
        $first_year = $first_post->format('Y');
        $last_year = $now->format('Y');

        $month_names = $this->_get_month_names();

        for ($year = $last_year; $year >= $first_year; $year--) {
            $year_count = 0;
            $month_data = [];

            // Loop over the months, start month is either first posting month
            // or January in all other cases. End months are treated similarly,
            // being december by default unless for the current year.
            if ($year == $first_year) {
                $first_month = $first_post->format('n');
            } else {
                $first_month = 1;
            }

            if ($year == $last_year) {
                $last_month = $now->format('n');
            } else {
                $last_month = 12;
            }

            for ($month = $first_month; $month <= $last_month; $month++) {
                $start_time = new DateTime();
                $start_time->setDate($year, $month, 1);
                $end_time = clone $start_time;
                $end_time->modify('+1 month');

                $month_count = $this->_compute_welcome_posting_count($start_time, $end_time);
                $year_count += $month_count;
                $total_count += $month_count;
                $month_data[$month] = [
                    'month' => $month,
                    'name' => $month_names[$month],
                    'url' => $this->router->generate('archive-month', ['year' => $year, 'month' => $month]),
                    'count' => $month_count,
                ];
            }

            $year_data[$year] = [
                'year' => $year,
                'url' => $this->router->generate('archive-year', ['year' => $year]),
                'count' => $year_count,
                'month_data' => $month_data,
            ];
        }
    }

    private function _get_month_names() : array
    {
        $names = [];
        $formatter = $this->_l10n->get_formatter();
        for ($i = 1; $i < 13; $i++) {
            $timestamp = mktime(0, 0, 0, $i, 1, 2011);
            $names[$i] = $formatter->customdate($timestamp, 'MMMM');
        }
        return $names;
    }

    /**
     * Displays the welcome page.
     *
     * Element sequence:
     *
     * - archive-welcome-start (Start of the archive welcome page)
     * - archive-welcome-year (Display of a single year, may not be called when there are no postings)
     * - archive-welcome-end (End of the archive welcome page)
     *
     * Context data for all elements:
     *
     * - int total_count (total number of postings w/o ACL restrictions)
     * - DateTime first_post (the first posting date, may be null)
     * - Array year_data (the year data, contains the year context info as outlined below)
     *
     * Context data for year elements:
     *
     * - int year (the year displayed)
     * - string url (url to display the complete year)
     * - int count (Number of postings in that year)
     * - array month_data (the monthly data)
     *
     * month_data will contain an associative array containing the following array of data
     * indexed by month number (1-12):
     *
     * - string 'url' => The URL to the month.
     * - string 'name' => The localized name of the month.
     * - int 'count' => The number of postings in that month.
     */
    public function _show_welcome(string $handler_id, array &$data)
    {
        midcom_show_style('archive-welcome-start');

        foreach ($data['year_data'] as $year => $year_data) {
            $data['year'] = $year;
            $data['url'] = $year_data['url'];
            $data['count'] = $year_data['count'];
            $data['month_data'] = $year_data['month_data'];
            midcom_show_style('archive-welcome-year');
        }

        midcom_show_style('archive-welcome-end');
    }

    /**
     * Shows the archive. Depending on the selected handler various constraints are added to
     * the QB. See the add_*_constraint methods for details.
     */
    public function _handler_list(array &$data, ?int $year = null, ?int $month = null, ?string $category = null)
    {
        $data['datamanager'] = new datamanager($data['schemadb']);
        // Get Articles, distinguish by handler.
        $qb = midcom_db_article::new_query_builder();
        $this->article_qb_constraints($qb);

        // Use helper functions to determine start/end
        if ($month) {
            $this->_set_startend_from_month($year, $month);
        } else {
            if (!$this->_config->get('archive_years_enable')) {
                throw new midcom_error_notfound('Year archive not allowed');
            }

            $this->_set_startend_from_year($year);

            if ($category) {
                $category = trim(strip_tags($category));
                if (   $data['datamanager']->get_schema('default')->has_field('categories')
                    && !$data['datamanager']->get_schema('default')->get_field('categories')['type_config']['allow_multiple']) {
                    $qb->add_constraint('extra1', '=', $category);
                } else {
                    $qb->add_constraint('extra1', 'LIKE', "%|{$category}|%");
                }
            }
        }


        $qb->add_constraint('metadata.published', '>=', $this->_start->format('Y-m-d H:i:s'));
        $qb->add_constraint('metadata.published', '<', $this->_end->format('Y-m-d H:i:s'));
        $qb->add_order('metadata.published', $this->_config->get('archive_item_order'));
        $this->_articles = $qb->execute();

        // Move end date one day backwards for display purposes.
        $now = new DateTime();
        if ($now < $this->_end) {
            $this->_end = $now;
        } else {
            $this->_end->modify('-1 day');
        }

        $timeframe = $this->_l10n->get_formatter()->timeframe($this->_start, $this->_end, 'date');
        $this->add_breadcrumb($this->router->generate('archive-year', ['year' => $year]), $timeframe);

        $data['start'] = $this->_start;
        $data['end'] = $this->_end;

        if ($this->_config->get('archive_in_navigation')) {
            $this->set_active_leaf($this->_topic->id . '_ARCHIVE');
        } else {
            $this->set_active_leaf($this->_topic->id . '_ARCHIVE_' . $year);
        }

        midcom::get()->metadata->set_request_metadata($this->get_last_modified(), $this->_topic->guid);
        midcom::get()->head->set_pagetitle("{$this->_topic->extra}: {$timeframe}");
    }

    /**
     * Computes the start/end dates to only query a given year. It will do validation
     * before processing, throwing 404 in case of incorrectly formatted dates.
     *
     * This is used by the archive-year handler.
     */
    private function _set_startend_from_year(int $year)
    {
        $now = new DateTime();
        if ($year > (int) $now->format('Y')) {
            throw new midcom_error_notfound("The year '{$year}' is in the future, no archive available.");
        }

        $endyear = $year + 1;
        $this->_start = new DateTime("{$year}-01-01 00:00:00");
        $this->_end = new DateTime("{$endyear}-01-01 00:00:00");
    }

    /**
     * Computes the start/end dates to only query a given month. It will do validation
     * before processing, throwing 404 in case of incorrectly formatted dates.
     *
     * This is used by the archive-month handler.
     */
    private function _set_startend_from_month(int $year, int $month)
    {
        if (   $month < 1
            || $month > 12) {
            throw new midcom_error_notfound("The month {$month} is not valid.");
        }

        $now = new DateTime();
        $this->_start = new DateTime("{$year}-" . sprintf('%02d', $month) . "-01 00:00:00");
        if ($this->_start > $now) {
            throw new midcom_error_notfound("The month '{$year}-" . sprintf('%02d', $month) . "' is in the future, no archive available.");
        }

        if ($month == 12) {
            $endyear = $year + 1;
            $endmonth = 1;
        } else {
            $endyear = $year;
            $endmonth = $month + 1;
        }

        $this->_end = new DateTime("{$endyear}-" . sprintf('%02d', $endmonth) . "-01 00:00:00");
    }

    /**
     * Displays the archive.
     */
    public function _show_list(string $handler_id, array &$data)
    {
        midcom_show_style('archive-list-start');
        if ($this->_articles) {
            $data['index_fulltext'] = $this->_config->get('index_fulltext');

            $total_count = count($this->_articles);
            $prefix = midcom_core_context::get()->get_key(MIDCOM_CONTEXT_ANCHORPREFIX);

            foreach ($this->_articles as $article_counter => $article) {
                try {
                    $data['datamanager']->set_storage($article);
                } catch (midcom_error $e) {
                    $e->log();
                    continue;
                }

                $data['article'] = $article;
                $data['article_counter'] = $article_counter;
                $data['article_count'] = $total_count;
                $data['view_url'] = $prefix . $this->get_url($article, $this->_config->get('link_to_external_url'));
                $data['local_view_url'] = $data['view_url'];
                $data['linked'] = ($article->topic !== $this->_topic->id);
                if ($data['linked']) {
                    $nap = new midcom_helper_nav();
                    $data['node'] = $nap->get_node($article->topic);
                }

                midcom_show_style('archive-list-item');
            }
        } else {
            midcom_show_style('archive-list-empty');
        }

        midcom_show_style('archive-list-end');
    }
}
