<?php
/*
 * Plugin Name: WP What Links Here
 * Plugin URI: http://wordpress.org/plugins/wp-what-links-here/
 * Description: This plugin implements "what links here" functionality in WordPress, like seen on e.g. Wikipedia.
 * Version: 1.0.2
 * Author: Peter J. Herrel
 * Author URI: http://peterherrel.com/
 * License: GPL3
 * Text Domain: wp_wlh
 * Domain Path: /lang
 */

/**
 * WP What Links Here implements "what links here" functionality in WordPress.
 *
 * LICENSE
 * This file is part of WP What Links Here.
 *
 * WP What Links Here is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * @package    WP What Links Here
 * @author     Peter J. Herrel <peterherrel@gmail.com>
 * @copyright  Copyright 2014 Peter J. Herrel
 * @license    http://www.gnu.org/licenses/gpl.txt GPL3
 * @link       https://wordpress.org/plugins/wp-what-links-here/
 * @link       https://github.com/diggy/wp-what-links-here/wiki/
 * @link       http://peterherrel.com/wordpress/plugins/wp-what-links-here/
 * @since      1.0.1
 */

// prevent direct access
if( ! defined( 'ABSPATH' ) )
    exit;

// check if class exists
if( ! class_exists( 'Wp_Wlh' ) )
{
/**
 * WP What Links Here Class.
 *
 * @since   1.0.1
 * @class   Wp_Wlh
 */
class Wp_Wlh
{
    var $version    = '1.0.2';

    /**
     * Constructor.
     *
     * @since   1.0.1
     * @access  protected
     * @return  void
     */
    protected function __construct()
    {
        // define cron interval, default: 7200 seconds = every two hours
        if( ! defined( 'WP_WLH_CRON_INTERVAL' ) ) define( 'WP_WLH_CRON_INTERVAL', 7200 );

        // filters
        add_filter( 'cron_schedules', array( $this, 'cron_schedules' ), 0, 1 );

        // admin
        if( is_admin() && ! defined( 'DOING_AJAX' ) )
        {
            // installation
            $this->activate();
            $this->deactivate();

            // admin
            add_filter( 'plugin_row_meta',  array( $this, 'plugin_row_meta' ),      10, 2 );
        }

        // actions
        add_action( 'init',                 array( $this, 'init' ),                 0 );
        add_action( 'post_updated',         array( $this, 'queue_add' ),            10, 1 );
        add_action( 'before_delete_post',   array( $this, 'queue_remove' ),         10, 1 );
        add_action( 'before_delete_post',   array( $this, 'before_delete_post' ),   10, 1 );

        // action hook
        do_action( 'wp_wlh_loaded' );
    }

    /**
     * Class init.
     *
     * @since   1.0.1
     * @access  public
     * @return  void
     */
    public static function launch()
    {
        static $instance = null;

        if ( ! $instance )
            $instance = new Wp_Wlh;

        return $instance;
    }

    /**
     * WordPress init.
     *
     * @since   1.0.1
     * @access  public
     * @return  void
     */
    public function init()
    {
        // add shortcode
        add_shortcode( 'wp_what_links_here',    array( $this, 'shortcode' ) );

        // init cron job
        add_action( 'wp_wlh_cron_job',          array( $this, 'do_cron' ) );
    }

    /**
     * Custom cron schedule.
     *
     * @since   1.0.1
     * @access  public
     * @return  void
     */
    public function cron_schedules( $schedules )
    {
        // add custom schedule
        $schedules['wp_wlh'] = array(
             'interval' => constant( 'WP_WLH_CRON_INTERVAL' )
            ,'display'  => sprintf( __( '%s cron schedule', 'wp_wlh' ), 'WP What Links Here' )
        );

        // return schedules
        return apply_filters( 'wp_wlh_cron_schedules', $schedules );
    }

    /**
     * Store cron data on post save.
     *
     * @since   1.0.1
     * @access  public
     * @param   int     $post_id    post ID
     * @return  bool                true on success
     */
    public function queue_add( $post_id )
    {
        // check post
        $post = $this->validate( $post_id );

        if( empty( $post ) )
            return false;

        // get queue
        $queue = array_filter( wp_parse_id_list( get_option( '_wp_wlh_cron_queue' ) ) );

        // check post against queue
        if( in_array( $post_id, $queue ) )
            return false;

        // add post to queue
        $queue[] = $post_id;

        // filter and format queue
        $queue = apply_filters( 'wp_wlh_queue_add', $this->implode( $queue ), $post_id );

        do_action( 'wp_wlh_queue_add', $queue, $post_id );

        // update queue
        return update_option( '_wp_wlh_cron_queue', $queue );
    }

    /**
     * Update cron data (on post deletion).
     *
     * @since   1.0.1
     * @access  public
     * @param   int     $post_id    post ID
     * @return  bool                true on success
     */
    public function queue_remove( $post_id )
    {
        // get queue
        $queue = array_filter( wp_parse_id_list( get_option( '_wp_wlh_cron_queue' ) ) );

        // check queue
        if( ! is_array( $queue ) || ! in_array( $post_id, $queue ) )
            return false;

        // remove post from queue
        $queue = $this->array_splice( $post_id, $queue );

        if ( false === $queue )
            return $queue;

        // filter and format queue
        $queue = apply_filters( 'wp_wlh_queue_remove', $this->implode( $queue ), $post_id );

        do_action( 'wp_wlh_queue_remove', $queue, $post_id );

        // update queue
        return update_option( '_wp_wlh_cron_queue', $queue );
    }

    /**
     * Execute cron job.
     *
     * @since   1.0.1
     * @access  public
     * @return  bool    True if option value has changed, false if not or if update failed.
     */
    public function do_cron()
    {
        // get queue
        $queue = array_filter( wp_parse_id_list( get_option( '_wp_wlh_cron_queue' ) ) );

        // check queue
        if( empty( $queue ) )
            return false;

        // process posts in queue
        foreach( $queue as $post_id )
            $this->do_cron_cb( $post_id );

        do_action( 'wp_wlh_do_cron', $queue );

        // empty the queue
        return update_option( '_wp_wlh_cron_queue', array() );
    }

    /**
     * Cron job callback.
     *
     * @since   1.0.1
     * @access  public
     * @param   int     $post_id    post ID
     * @return  bool                always returns true
     */
    public function do_cron_cb( $post_id )
    {
        // check post
        $post = $this->validate( $post_id );

        if( empty( $post ) )
            return false;

        // parse content, get links
        $links = $this->get_links( $post_id );

        // check if links
        if( empty( $links ) )
        {
            $linking_to = self::linking_to( $post_id );

            if( ! empty( $linking_to ) )
            {
                foreach( $linking_to as $linked_to )
                    $this->update( 'linking_here', 'remove', $linked_to, $post_id );

                // delete post meta
                delete_post_meta( $post_id, '_wp_wlh_linking_to' );
            }

            return $links;
        }

        // we have links
        $links      = array_unique( $links );
        $to_process = array();

        // process links
        foreach( $links as $link ) :

            // check if internal link
            if( false === strpos( $link, apply_filters( 'wp_wlh_check_link', home_url() ) ) )
                continue;

            // get post ID by URL
            $link = url_to_postid( $link );

            // skip if no post ID
            if( empty( $link ) || is_null( get_post( $link ) ) )
                continue;

            $to_process[] = $link;

        endforeach;

        return $this->process_links( $post_id, array_unique( $to_process ) );
    }

    /**
     * Process all relevant links found in post content.
     *
     * @since   1.0.1
     * @access  public
     * @param   int     $post_id    post ID
     * @param   array   $to_process array of post IDs
     * @return  bool                always returns true
     */
    public function process_links( $post_id, $to_process )
    {
        $linking_to = self::linking_to( $post_id );

        /*
         * remove obsolete linkers
         */
        $diffs = array_diff( $linking_to, $to_process );

        if( $diffs ) :

            foreach( $diffs as $linked_to )
                $this->update( 'linking_here', 'remove', $linked_to, $post_id );

        endif;

        /*
         * add current linkers
         */
        foreach( $to_process as $linked_to )
            $this->update( 'linking_here', 'add', $linked_to, $post_id );

        /*
         * update linking to
         */
        update_post_meta( $post_id, '_wp_wlh_linking_to', $this->implode( $to_process ) );

        return true;
    }

    /**
     * Update post data on post deletion.
     *
     * @since   1.0.1
     * @access  public
     * @param   int     $post_id    post ID
     * @return  bool                true on success
     */
    public function before_delete_post( $post_id )
    {
        // posts linked to
        $linking_to = self::linking_to( $post_id );

        if( ! empty( $linking_to ) )
        {
            // process posts linked to
            foreach( $linking_to as $target )
                $this->update( 'linking_here', 'remove', $target, $post_id );
        }

        // posts linking here
        $linking_here = self::linking_here( $post_id );

        if( ! empty( $linking_here ) )
        {
            // process posts linking here
            foreach( $linking_here as $source )
                $this->update( 'linking_to', 'remove', $source, $post_id );
        }

        return true;
    }

    /**
     * Shortcode callback.
     *
     * @since   1.0.1
     * @access  public
     * @return  void
     */
    public function shortcode( $atts = array(), $content = null )
    {
        $html = '';

        // get post ID
        if( in_the_loop() ) {
            $post_id = get_the_ID();
        } else {
            global $post;
            $post_id = $post->ID;
        }

        $post__in = self::linking_here( $post_id );

        if( empty( $post__in ) )
            return $html;

        $query = new WP_Query( apply_filters( 'wp_wlh_shortcode_query_args', array(
             'post_type'        => $this->post_types()
            ,'post_status'      => array( 'publish' )
            ,'post__in'         => $post__in
            ,'posts_per_page'   => -1
        ) ) );

        if( $query->have_posts() ) :

            $html .= apply_filters( 'wp_wlh_shortcode_output_open', '<ul class="wp-wlh-items">', get_the_ID() );

            while( $query->have_posts() )
            {
                $query->the_post();
                $item = '<li><a href="' . get_permalink() . '" title="' . esc_attr( sprintf( __( 'Permalink to %s', 'wp_wlh' ), the_title_attribute( 'echo=0' ) ) ) . '" rel="bookmark">' . get_the_title() . '</a></li>';
                $html .= apply_filters( 'wp_wlh_shortcode_output_item', $item, get_the_ID() );
            }

            $html .= apply_filters( 'wp_wlh_shortcode_output_close', '</ul>', get_the_ID() );

        endif;

        wp_reset_postdata();

        return apply_filters( 'wp_wlh_shortcode_output', $html );
    }

    /* processing methods *****************************************************************************/

    /**
     * Update post meta values.
     *
     * @since   1.0.1
     * @access  private
     * @param   string  $meta_key
     * @param   string  $action
     * @param   int     $object
     * @param   int     $subject
     * @return  bool                true on success, otherwise false
     */
    private function update( $meta_key = '', $action = '', $object = 0, $subject = 0 )
    {
        switch( $meta_key ) :

            case 'linking_here' :

                $linking_here = self::linking_here( $object );

                switch( $action ) :

                    case 'add' :

                        if( in_array( $subject, $linking_here ) )
                            continue;

                        // add post ID to array
                        $linking_here[] = $subject;

                        // update post meta
                        update_post_meta( $object, '_wp_wlh_linking_here', $this->implode( $linking_here ) );

                        break;

                    case 'remove' :

                        if( empty( $linking_here ) || ! in_array( $subject, $linking_here ) )
                            continue;

                        // remove post ID
                        $linking_here = $this->array_splice( $subject, $linking_here );

                        // update post meta
                        if( empty( $linking_here ) )
                            delete_post_meta( $object, '_wp_wlh_linking_here' );
                        else
                            update_post_meta( $object, '_wp_wlh_linking_here', $this->implode( $linking_here ) );

                        break;

                    default :

                        return false;

                        break;

                endswitch;

                break;

            case 'linking_to' :

                $linking_to = self::linking_to( $object );

                switch( $action ) :

                    case 'remove' :

                        if( empty( $linking_to ) || ! in_array( $subject, $linking_to ) )
                            continue;

                        // remove post ID from array
                        $linking_to = $this->array_splice( $subject, $linking_to );

                        // update post meta
                        if( empty( $linking_to ) )
                            delete_post_meta( $object, '_wp_wlh_linking_to' );
                        else
                            update_post_meta( $object, '_wp_wlh_linking_to', $this->implode( $linking_to ) );

                        break;

                    default :

                        return false;

                        break;

                endswitch;

                break;

            default :

                return false;

                break;

        endswitch;

        return true;
    }

    /* retrieval methods **************************************************************************/

    /**
     * Get allowed post types
     *
     * @since   1.0.2
     * @access  public
     * @return  void
     */
    public function post_types()
    {
        return apply_filters( 'wp_wlh_post_types', array_keys( get_post_types( array( 'public' => true ), 'names' ) ) );
    }

    /**
     * Get what's linking here.
     *
     * @since   1.0.1
     * @access  public
     * @param   int         $post_id
     * @return  array|bool
     */
    public static function linking_here( $post_id = 0 )
    {
        // check post
        if( ! $post = get_post( $post_id ) )
            return false;

        $linking_here = get_post_meta( $post->ID, '_wp_wlh_linking_here', true );
        $linking_here = array_filter( wp_parse_id_list( $linking_here ) );

        return (array) apply_filters( 'wp_wlh_linking_here', $linking_here, $post->ID );
    }

    /**
     * Get what we're linking to.
     *
     * @since   1.0.1
     * @access  public
     * @param   int         $post_id
     * @return  array|bool
     */
    public static function linking_to( $post_id = 0 )
    {
        if( ! $post = get_post( $post_id ) )
            return false;

        $linking_to = get_post_meta( $post->ID, '_wp_wlh_linking_to', true );
        $linking_to = array_filter( wp_parse_id_list( $linking_to ) );

        return (array) apply_filters( 'wp_wlh_linking_to', $linking_to, $post->ID );
    }

    /**
     * Extract links from post content.
     *
     * Scans post content, puts all elements with an a tag in an array and returns it.
     *
     * @since   1.0.1
     * @acces   public
     * @param   int         $post_id    Required. Post ID
     * @return  array|bool              Results if post content contains hyperlinks, otherwise false
     */
    public function get_links( $post_id )
    {
        // sanity check
        if( ! $post = get_post( $post_id ) )
            return false;

        // extract links from $post->post_content
        $links = array();

        $dom = new DomDocument;
        $dom->loadHTML( apply_filters( 'wp_wlh_the_content', $post->post_content, $post_id ) );
        $elements = $dom->getElementsByTagName( 'a' );

        // if element has attribute, add to array
        if( $elements )
            foreach ( $elements as $node )
                if ( $node->hasAttribute( 'href' ) )
                    $links[] = $node->getAttribute( 'href' );

        if( empty( $links ) )
            return false;

        // return links
        return apply_filters( 'wp_wlh_get_links', $links, $post_id );
    }

    /* helper methods *****************************************************************************/

    /**
     * Validate post ID.
     *
     * @since   1.0.1
     * @access  private
     * @param   int         $post_id    post ID
     * @return  bool|object             post object on success, otherwise false
     */
    private function validate( $post_id = 0 )
    {
        // check post
        if( ! $post = get_post( $post_id ) )
            return false;

        // check post status
        if( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status )
            return false;

        // check post type
        if( ! in_array( $post->post_type, array_values( $this->post_types() ) ) )
            return false;

        return $post;
    }

    /**
     * Remove item from array.
     *
     * @since   1.0.1
     * @access  private
     * @param   int         $post_id    post ID to remove
     * @param   array       $array      array to search
     * @return  array|bool              array on success, otherwise false.
     */
    private function array_splice( $post_id, $array )
    {
        $offset = array_search( $post_id, $array );

        if ( false === $offset )
            return false;

        array_splice( $array, $offset, 1 );

        return $array;
    }

    /**
     * Parses an array, comma- or space-separated list of IDs and implodes it.
     *
     * @since   1.0.1
     * @access  private
     * @param   string|array    $var    array, comma- or space-separated list of IDs
     * @return  string                  sanitized, comma-separated list of IDs
     */
    private function implode( $var )
    {
        return implode( ',', array_filter( wp_parse_id_list( $var ) ) );
    }

    /* installation methods ***********************************************************************/

    /**
     * Activation.
     *
     * @since   1.0.1
     * @access  public
     * @return  void
     */
    public function activate()
    {
        register_activation_hook( __FILE__, array( $this, 'install' ) );

        if( get_option( 'wp_wlh_db_version' ) != $this->version )
            add_action( 'init', array( $this, 'install_cb' ), 1 );
    }

    /**
     * Deactivation.
     *
     * @since   1.0.1
     * @access  public
     * @return  void
     */
    public function deactivate()
    {
        register_deactivation_hook( __FILE__, array( $this, 'uninstall' ) );
    }

    /**
     * Activation hook.
     *
     * @since   1.0.1
     * @access  public
     * @return  void
     */
    public function install()
    {
        global $wp_version;
    
        $required = '3.7';

        if( version_compare( $wp_version, $required, '<' ) )
        {
            deactivate_plugins( basename( __FILE__ ) );

            wp_die(
                 sprintf( __( 'Alas, %1$s requires %2$s version %3$s or higher.', 'wp_wlh' ), 'WP What Links Here', 'WordPress', $required )
                ,sprintf( __( '%s Plugin Activation Error', 'wp_wlh' ), 'WP What Links Here' )
                ,array( 'response' => 200, 'back_link' => true )
            );
        }

        update_option( 'wp_wlh_install', 1 );

        $this->install_cb();
    }

    /**
     * Install callback.
     *
     * @since   1.0.1
     * @access  public
     * @return  void
     */
    public function install_cb()
    {
        // db version
        update_option( 'wp_wlh_db_version', $this->version );

        // setup queue
        if( false === get_option( '_wp_wlh_cron_queue' ) )
        {
            $queue = array();
            add_option( '_wp_wlh_cron_queue', $queue, '', 'no' ); // no autoload
        }

        // setup cron
        $timestamp = wp_next_scheduled( 'wp_wlh_cron_job' );

        if( false === $timestamp )
            wp_schedule_event( time(), 'wp_wlh', 'wp_wlh_cron_job' );
    }

    /**
     * Deactivation hook.
     *
     * @since   1.0.1
     * @access  public
     * @return  void
     */
    public function uninstall()
    {
        update_option( 'wp_wlh_uninstall', 1 );

        $this->uninstall_cb();
    }

    /**
     * Uninstall callback.
     *
     * @since   1.0.1
     * @access  public
     * @return  void
     */
    public function uninstall_cb()
    {
        // clear cron hook
        wp_clear_scheduled_hook( 'wp_wlh_cron_job' );

        // delete queue
        delete_option( '_wp_wlh_cron_queue' );

        // delete options
        delete_option( 'wp_wlh_db_version' );
        delete_option( 'wp_wlh_install' );
        delete_option( 'wp_wlh_uninstall' );
    }

    /* admin **************************************************************************************/

    /**
     * Plugin Row Meta.
     *
     * @since   1.0.1
     * @access  public
     * @param   $links
     * @param   $file
     * @return  array
     */
    public function plugin_row_meta( $links, $file )
    {
        if( $file === plugin_basename( __FILE__ ) )
        {
            $links[] = sprintf( '<a target="_blank" href="%s">%s</a>', esc_url( 'https://github.com/diggy/wp-what-links-here/wiki/' ), __( 'Wiki', 'wp_wlh' ) );
            $links[] = sprintf( '<a target="_blank" href="%s">%s</a>', esc_url( 'https://wordpress.org/support/plugin/wp-what-links-here' ), __( 'Support', 'wp_wlh' ) );
            $links[] = sprintf( '<a target="_blank" href="%s">%s</a>', esc_url( 'https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BULEF95ABJC4Y' ), __( 'Donate', 'wp_wlh' ) );
        }

        return $links;
    }

} // end class

// initialize class
Wp_Wlh::launch();

} // end class_exists

/**
 * Get what's linking here
 *
 * A wrapper function. Use the ids returned by this function for the post__in parameter in
 * get_posts() or WP_Query. For an example see the shortcode callback in Wp_Wlh::shortcode().
 * Make sure you only query posts with a 'publish' post_status (as you probably don't want to link
 * to posts in trash).
 *
 * @since   1.0.1
 * @param   int     $post_id    Post ID
 * @return  array               array of post IDs, or an empty array
 */
function wp_what_links_here( $post_id = 0 )
{
    return Wp_Wlh::linking_here( $post_id );
}

/* end of file wp-what-links-here.php */
