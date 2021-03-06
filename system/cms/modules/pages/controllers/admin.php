<?php

use Pyro\Module\Comments\Model\Comment;
use Pyro\Module\Navigation;
use Pyro\Module\Pages\Model\Page;
use Pyro\Module\Pages\Model\PageType;
use Pyro\Module\Users;

/**
 * Pages controller
 *
 * @author      PyroCMS Dev Team
 * @package     PyroCMS\Core\Modules\Pages\Controllers
 */
class Admin extends Admin_Controller
{
    /**
     * The current active section
     *
     * @var string
     */
    protected $section = 'pages';

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        // Load the required classes
        $this->lang->load('pages');
        $this->lang->load('page_types');

        $this->load->driver('Streams');
    }

    /**
     * Index methods, lists all pages
     */
    public function index()
    {
        $pages = Page::with('children')->get();

        $this->template

            ->title($this->module_details['name'])

            ->append_js('jquery/jquery.ui.nestedSortable.js')
            ->append_js('jquery/jquery.cooki.js')
            ->append_js('jquery/jquery.stickyscroll.js')
            ->append_js('module::index.js')

            ->append_css('module::index.css')

            ->set('pages', $pages)
            ->build('admin/index');
    }

    /**
     * Choose a page type
     */
    public function choose_type()
    {
        $types = PageType::all();

        // Do we have a parent ID?
        $parent = ($this->input->get('parent')) ? '&parent='.$this->input->get('parent') : null;

        // Who needs a menu when there is only one option?
        if (count($types) == 1) {
            redirect('admin/pages/create?page_type='.$types[0]->id.$parent);
        }

        // Directly output the menu if it's for the modal.
        // All we need is the <ul>.
        if ($this->input->is_ajax_request()) {
            $html  = '<h4>'.lang('pages:choose_type_title').'</h4>';
            $html .= '<ul class="modal_select">';

            foreach ($types as $pt) {
                $html .= '<li><a href="'.site_url('admin/pages/create?page_type='.$pt->id.$parent).'"><strong>'.$pt->title.'</strong>';

                if (trim($pt->description)) {
                    $html .= ' | '.$pt->description;
                }

                $html .= '</a></li>';
            }

            echo $html .= '</ul>';

            return;
        }

        // If this is not being displayed in the modal, we can
        // display an entire page.
        $this->template
            ->set('parent', $parent)
            ->set('page_types', $types)
            ->build('admin/choose_type');
    }

    /**
     * Order the pages and record their children
     *
     * Grabs `order` and `data` from the POST data.
     */
    public function order()
    {
        $order  = $this->input->post('order');
        $data   = $this->input->post('data');
        $root_pages = isset($data['root_pages']) ? $data['root_pages'] : array();

        if (is_array($order)) {

            //reset all parent > child relations
            $this->page_m->update_all(array('parent_id' => 0));

            foreach ($order as $i => $page) {
                $id = str_replace('page_', '', $page['id']);

                //set the order of the root pages
                $this->page_m->update($id, array('order' => $i), true);

                //iterate through children and set their order and parent
                $this->page_m->_set_children($page);
            }

            // rebuild page URIs
            $this->page_m->update_lookup($root_pages);

            //@TODO Fix Me Bro https://github.com/pyrocms/pyrocms/pull/2514
            $this->cache->clear('navigation_m');
            $this->cache->clear('page_m');

            Events::trigger('page_ordered', array($order, $root_pages));
        }
    }

    /**
     * Get the details of a page.
     *
     * @param int $id The id of the page.
     */
    public function ajax_page_details($id)
    {
        $page = Page::find($id);

        $page->meta_keywords = Keywords::get_string($page->meta_keywords);

        $this->load->view('admin/ajax/page_details', compact('page'));
    }

    /**
     * Show a page preview
     *
     * @param int $id The id of the page.
     */
    public function preview($id = 0)
    {
        $page = Page::find($id);

        $this->template
            ->set_layout('modal', 'admin')
            ->build('admin/preview', compact('page'));
    }

    /**
     * Duplicate a page
     *
     * @param int $id The ID of the page
     * @param null $parent_id The ID of the parent page, if this is a recursive nested duplication
     */
    public function duplicate($id, $parent_id = null)
    {
        $page  = Page::with('children')->find($id);

        $new_slug = $page->slug;

        // No parent around? Do what you like
        if (is_null($parent_id)) {
            do {
                // Turn "Foo" into "Foo 2"
                $page->title = increment_string($page->title, ' ', 2);

                // Turn "foo" into "foo-2"
                $page->slug = increment_string($page->slug, '-', 2);

                // Find if this already exists in this level
                $has_dupes = Page::where('slug', $page->slug)
                    ->where('parent_id', $page->parent_id)
                    ->count() > 0;
            } while ($has_dupes === true);

        // Oop, a parent turned up, work with that
        } else {
            $page->parent_id = $parent_id;
        }

        $page->restricted_to = null;
        $page->navigation_group_id = 0;

        throw new Exception('FAIL BECAUSE STREAMS ARENT ELOQUENT YET');

        // TODO Streams need to be converted to Eloquent so we can make a "stream" or "entry" relationship
        $new_page = Page::create($page->toArray());

        // TODO Make this bit into page->children()->create($datastuff);
        // $this->streams_m->get_stream($page['stream_id']);

        foreach ($page->children as $child) {
            $this->duplicate($child->id, $new_page);
        }

        // only allow a redirect when everything is finished (only the top level page has a null parent_id)
        if (is_null($parent_id)) {
            redirect('admin/pages');
        }
    }

    /**
     * Create a new page
     *
     * @param int $parent_id The id of the parent page.
     */
    public function create()
    {
        $page = new Page;

        // What type of page are we creating?
        $page_type = PageType::find($this->input->get('page_type'));

        // Redirect to the page type selection menu if no page type was specified
        if (! $page_type) {
            redirect('admin/pages/choose_type');
        }
    
        // Get the stream that we are using for this page type.
        $stream = $page_type->getStream();

        $stream_validation = $this->_setup_stream_fields($stream);

        if ($this->input->method() === 'post') {
            
            $input = $this->input->post();

            // Do they have permission to proceed?
            if ($input['status'] === 'live') {
                role_or_die('pages', 'put_live');
            }

            // 
            $page->slug             = $input['slug'];
            $page->title            = $input['title'];
            $page->parent_id        = (int) $input['parent_id'];
            $page->type_id          = (int) $page_type->id;
            $page->css              = isset($input['css']) ? $input['css'] : null;
            $page->js               = isset($input['js']) ? $input['js'] : null;
            $page->meta_title       = isset($input['meta_title']) ? $input['meta_title'] : null;
            $page->meta_keywords    = isset($input['meta_keywords']) ? $this->keywords->process($input['meta_keywords']) : null;
            $page->meta_description = isset($input['meta_description']) ? $input['meta_description'] : null;
            $page->rss_enabled      = ! empty($input['rss_enabled']);
            $page->comments_enabled = ! empty($input['comments_enabled']);
            $page->status           = $input['status'];
            $page->created_on       = time();
            $page->restricted_to    = isset($input['restricted_to']) ? implode(',', $input['restricted_to']) : 0;
            $page->strict_uri       = ! empty($input['strict_uri']);
            $page->is_home          = ! empty($input['is_home']);
            $page->order            = time();

            // Insert the page data, along with
            if ($page->save()) {

                if ( ! empty($input['is_home'])) {
                    $page->setHomePage();
                }

                // We define this for the field type
                define('PAGE_ID', $page->id);

                $page->buildLookup();

                // Add a Navigation Link
                if ( ! empty($input['navigation_group_id']) and is_array($input['navigation_group_id'])) {
                    foreach ($input['navigation_group_id'] as $group_id) {

                        $link = Navigation\Model\Link::create(array(
                            'title'                 => $page->title,
                            'link_type'             => 'page',
                            'page_id'               => $page->id,
                            'navigation_group_id'   => $group_id
                        ));

                        if ($link) {

                            //@TODO Fix Me Bro https://github.com/pyrocms/pyrocms/pull/2514
                            $this->cache->clear('navigation_m');

                            Events::trigger('post_navigation_create', $link);
                        }
                    }
                }

                // Add the stream data.
                if ($stream) {
                    $this->load->driver('Streams');

                    // Insert the stream using the streams driver.
                    if ($entry_id = $this->streams->entries->insert_entry($input, $stream->stream_slug, $stream->stream_namespace)) {
                        // Update with our new entry id
                        if ( ! $this->db->limit(1)->where('id', $page->id)->update('pages', array('entry_id' => $entry_id))) {
                            return false;
                        }
                    } else {
                        // Something went wrong. Abort!
                        return false;
                    }
                }

                $this->cache->clear('page_m');

                Events::trigger('page_created', $page);

                $this->session->set_flashdata('success', lang('pages:create_success'));

                // Redirect back to the form or main page
                $input['btnAction'] === 'save_exit'
                    ? redirect('admin/pages')
                    : redirect('admin/pages/edit/'.$page->id);

            }
        }

        // Go through our stream fields and set the current value
        // for the form. Since we are creating a new form, this should
        // simply be the post data if it is available.
        $assignments = $this->streams->streams->get_assignments($stream->stream_slug, $stream->stream_namespace);
        $page_content_data = array();

        if ($assignments) {
            foreach ($assignments as $assign) {
                $page_content_data[$assign->field_slug] = $this->input->post($assign->field_slug);
            }
        }

        $stream_fields = $this->streams_m->get_stream_fields($this->streams_m->get_stream_id_from_slug($stream->stream_slug, $stream->stream_namespace));

        // Set Values
        $values = $this->fields->set_values($stream_fields, null, 'new');

        // Run stream field events
        $this->fields->run_field_events($stream_fields, array(), $values);

        // Set some data that both create and edit forms will need
        $this->_form_data();

        // Load WYSIWYG editor
        $this->template
            ->title($this->module_details['name'], lang('pages:create_title'))
            ->append_metadata($this->load->view('fragments/wysiwyg', array(), true))
            ->set('page', $page)
            ->set('stream_fields', $this->streams->fields->get_stream_fields($stream->stream_slug, $stream->stream_namespace, $values))
            ->build('admin/form');
    }

    /**
     * Edit an existing page
     *
     * @param int $id The id of the page.
     */
    public function edit($id = 0)
    {
        // We are lost without an id. Redirect to the pages index.
        $id or redirect('admin/pages');

        $this->template->set('parent_id', null);

        // The user needs to be able to edit pages.
        role_or_die('pages', 'edit_live');

        // Retrieve the page data along with its data as part of the array.
        $page = Page::with('type')->find($id);

        // Got page?
        if (is_null($page)) {
            // Maybe you would like to create one?
            $this->session->set_flashdata('error', lang('pages:page_not_found_error'));
            redirect('admin/pages/choose_type');
        }

        // This is a temporary global until the page chunk field type is removed
        ci()->page_id = $id;

        // Note: we don't need to get the page type
        // from the URL since it is present in the $page data

        if (! $page->type) {
            show_error('No page type found.');
        }

        $stream = $page->type->getStream();

        $stream_validation = $this->_setup_stream_fields($stream, 'edit', $page->entry_id);

        // If there's a keywords hash
        if ($page->meta_keywords != '') {
            // Get comma-separated meta_keywords based on keywords hash
            $old_keywords_hash = $page->meta_keywords;
            $page->meta_keywords = Keywords::get_string($page->meta_keywords);
        }

        // Turn the CSV list back to an array
        $page->restricted_to = explode(',', $page->restricted_to);

        // Did they even submit?
        if (($input = $this->input->post())) {

            // do they have permission to proceed?
            if ($input['status'] == 'live') {
                role_or_die('pages', 'put_live');
            }

            // Were there keywords before this update?
            if (isset($old_keywords_hash)) {
                $input['old_keywords_hash'] = $old_keywords_hash;
            }

            // Set this one page as the homepage, and not the others
            if ( ! empty($input['is_home'])) {
                $page->setHomePage();
            }

            // Translate the data of restricted_to to something we can use in the form.
            if ($input['restricted_to'][0] == '') {
                $input['restricted_to'][0] = '0';
            }

            // Assign post data to the page
            $page->slug             = $input['slug'];
            $page->title            = $input['title'];
            $page->parent_id        = (int) $input['parent_id'];
            $page->css              = isset($input['css']) ? $input['css'] : null;
            $page->js               = isset($input['js']) ? $input['js'] : null;
            $page->meta_title       = isset($input['meta_title']) ? $input['meta_title'] : '';
            $page->meta_keywords    = isset($input['meta_keywords']) ? Keywords::process($input['meta_keywords'], (isset($input['old_keywords_hash'])) ? $input['old_keywords_hash'] : null) : '';
            $page->meta_description = isset($input['meta_description']) ? $input['meta_description'] : '';
            $page->rss_enabled      = ! empty($input['rss_enabled']);
            $page->comments_enabled = ! empty($input['comments_enabled']);
            $page->status           = $input['status'];
            $page->updated_on       = time();
            $page->restricted_to    = isset($input['restricted_to']) ? implode(',', $input['restricted_to']) : '0';
            $page->strict_uri       = ! empty($input['strict_uri']);

            // validate and insert
            if ($page->save()) {

                $page->buildLookup();

                // Add the stream data.
                if ($stream and $page->entry_id) {
                    $this->load->driver('Streams');

                    // Insert the stream using the streams driver. Our only extra field is the page_id
                    // which links this particular entry to our page.
                    $this->streams->entries->update_entry($page->entry_id, $input, $stream->stream_slug, $stream->stream_namespace);
                }

                $this->session->set_flashdata('success', sprintf(lang('pages:edit_success'), $page->title));

                Events::trigger('page_updated', $page);

                $this->cache->clear('page_m');
                //@TODO Fix Me Bro https://github.com/pyrocms/pyrocms/pull/2514
                $this->cache->clear('navigation_m');

                // Mission accomplished!
                $input['btnAction'] == 'save_exit'
                    ? redirect('admin/pages')
                    : redirect('admin/pages/edit/'.$id);
            }
        }


        // Go through our stream fields and set the current value
        // for the form. Since we are creating a new form, this should
        // simply be the post data if it is available.

        $assignments = $this->streams->streams->get_assignments($stream->stream_slug, $stream->stream_namespace);
        $page_content_data = array();

        // Get straight raw from the db
        $page_stream_entry_raw = $this->db->limit(1)->where('id', $page->entry_id)->get($stream->stream_prefix.$stream->stream_slug)->row();

        if ($assignments) {
            foreach ($assignments as $assign) {
                $from_db = isset($page_stream_entry_raw->{$assign->field_slug}) ? $page_stream_entry_raw->{$assign->field_slug} : null;

                $page_content_data[$assign->field_slug] = isset($_POST[$assign->field_slug]) ? $_POST[$assign->field_slug] : $from_db;
            }
        }

        $stream_fields = $this->streams_m->get_stream_fields($this->streams_m->get_stream_id_from_slug($stream->stream_slug, $stream->stream_namespace));

        // Set Values
        $values = $this->fields->set_values($stream_fields, $page_stream_entry_raw, 'edit');

        // Run stream field events
        $this->fields->run_field_events($stream_fields, array(), $values);

        $this->_form_data();

        $this->template
            ->title($this->module_details['name'], sprintf(lang('pages:edit_title'), $page->title))
            ->append_metadata($this->load->view('fragments/wysiwyg', array() , true))
            ->append_css('module::page-edit.css')
            ->set('stream_fields', $this->streams->fields->get_stream_fields($stream->stream_slug, $stream->stream_namespace, $values, $page->entry_id))
            ->set('page', $page)
            ->build('admin/form');
    }

    /**
     * Setup Stream fields
     *
     * Sets up our validation and some other common
     * elements for our page create/edit functions.
     *
     * @param   obj
     * @param   string - new or edit
     * @param   int - entry id
     * @return  obj - the stream object
     */
    private function _setup_stream_fields($stream, $method = 'new', $id = null)
    {
        $this->load->driver('Streams');

        // Get validation for our page fields.
        $page_validation = $this->streams->streams->validation_array($stream->stream_slug, $stream->stream_namespace, $method, array(), $id);
    }

    /**
     * Sets up common form inputs.
     *
     * This is used in both the creation and editing forms.
     */
    private function _form_data()
    {
        $page_types = PageType::orderBy('title')->get();

        $this->template->page_types = array_for_select($page_types->toArray(), 'id', 'title');

        // Load navigation list
        $this->template->navigation_groups = Navigation\Model\Group::getGroupOptions();
        $this->template->group_options = Users\Model\Group::getGroupOptions();

        $this->template
            ->append_js('jquery/jquery.tagsinput.js')
            ->append_js('jquery/jquery.cooki.js')
            ->append_js('module::form.js')
            ->append_css('jquery/jquery.tagsinput.css');
    }

    /**
     * Delete a page.
     *
     * @param int $id The id of the page to delete.
     */
    public function delete($id = 0)
    {
        // The user needs to be able to delete pages.
        role_or_die('pages', 'delete_live');

        // @todo Error of no selection not handled yet.
        $ids = ($id) ? array($id) : $this->input->post('action_to');

        // Go through the array of slugs to delete
        if ( ! empty($ids)) {

            foreach ($ids as $id) {

                if ($id !== 1) {
                    if ( ! $page = Page::find($id)) {
                        continue;
                    }

                    $page->delete();

                    $deleted_ids = $id;

                    // Delete any page comments for this entry
                    $comments = Comment::where('module','=','pages')->where('entry_id','=',$id)->delete();

                    // Wipe cache for this model, the content has changd
                    $this->cache->clear('page_m');
                    //@TODO Fix Me Bro https://github.com/pyrocms/pyrocms/pull/2514
                    $this->cache->clear('navigation_m');

                } else {
                    $this->session->set_flashdata('error', lang('pages:delete_home_error'));
                }
            }

            // Some pages have been deleted
            if ( ! empty($deleted_ids)) {
                Events::trigger('page_deleted', $deleted_ids);

                // Only deleting one page
                if ( count($deleted_ids) == 1 ) {
                    $this->session->set_flashdata('success', sprintf(lang('pages:delete_success'), $deleted_ids[0]));

                // Deleting multiple pages
                } else {
                    $this->session->set_flashdata('success', sprintf(lang('pages:mass_delete_success'), count($deleted_ids)));
                }

            // For some reason, none of them were deleted
            } else {
                $this->session->set_flashdata('notice', lang('pages:delete_none_notice'));
            }
        }

        redirect('admin/pages');
    }

}