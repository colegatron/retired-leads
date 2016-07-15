<?php

class Leads_Batch_Processor {

    static $leads;

    /**
     * Leads_Batch_Processor constructor.
     */
    public function __construct(){
        self::load_hooks();
    }

    /**
     * Load hooks and filters
     */
    public static function load_hooks(){
        add_action( 'admin_menu' , array( __CLASS__ , 'init_listener'));
    }


    /**
     * Listens for batch processing
     */
    public static function init_listener() {

        /* check if batch processing event is flagged */
        if ( !get_option('leads_batch_processing' , false )) {
            return;
        }

        /* Temporarily create admin page for visualizing batch processing */
        add_submenu_page(
            'edit.php?post_type=wp-lead',
            __( 'Batch Processing', 'inbound-pro' ),
            __( 'Batch Processing', 'inbound-pro' ),
            'manage_options',
            'leads-batch-processing',
            array( __CLASS__ , 'process_batches' )
        );

        /* Do not let user escape until all leads have been processed */
        if (!isset($_GET['page']) || $_GET['page'] != 'leads-batch-processing' ) {
            header('Location: ' . admin_url('edit.php?post_type=wp-lead&page=leads-batch-processing'));
            exit;
        }

    }


    /**
     * get leads from wp-lead post type
     */
    public static function get_leads( $args ) {

        $args = array(
            'post_type' => 'wp-lead',
            'posts_per_page' => $args['posts_per_page'],
            'offset' => $args['offset'] * $args['posts_per_page'],
            'post_status' => 'any',
            'orderby' => 'date',
            'order'  => 'DESC',
        );

        self::$leads = get_posts( $args );

    }

    /**
     * Run the batch processing method stored in leads_batch_processing option
     */
    public static function process_batches() {

        /* load batch processing data into variable */
        $args = get_option('leads_batch_processing');

        echo '<h1>' . __( 'Processing Batches!' , 'inbound-pro' ) .'</h1>';
        echo '<div class="wrap">';

        /* run the method */
        call_user_func(
            array(__ClASS__, $args['method']),
            $args
        );

        echo '</div>';

    }

    public static function delete_flag() {
        delete_option('leads_batch_processing');
    }

    /**
     * Loops through all leads and imports events stored in metapairs into inbound_events table
     */
    public static function import_events_table_112015( $args ) {

        $total = wp_count_posts('wp-lead');
        $pages = ceil( $total->publish / $args['posts_per_page'] );

        /* let the user know the processing status */
        self::get_leads( $args );
        echo  sprintf( __(  '%s of %s steps complete. Please wait...' , 'inbound-pro' ) , $args['offset'] , $pages );


        /* if all leads are processed echo complete and delete batch job */
        if (!self::$leads || $args['offset'] > $pages ) {
            self::delete_flag();
            echo '<br>';
            _e( 'All done!' , 'inbound-pro' );
            exit;
        }

        echo '<br><br>';
        echo '<img src="'.admin_url('images/spinner-2x.gif').'">';

        foreach (self::$leads as $ID => $lead) {

            /* import form submission events into inbound_events table */
            $conversion_data = get_post_meta( $lead->ID , 'wpleads_conversion_data', true);
            if ($conversion_data) :

                $conversion_data = json_decode($conversion_data, true);

                foreach ($conversion_data as $entry) {

                    /* skip data without ids */
                    if ( !isset($entry['id']) || !$entry['id'] ) {
                        continue;
                    }

                    /* check if call to action or content page; skip call to actions, they are handled later */
                    $post_type = get_post_type( $entry['id'] );
                    if ($post_type == 'wp-call-to-action') {
                        continue;
                    }

                    /* assume the rest are form submissions */
                    Inbound_Events::store_event(array(
                        'event_name' => 'inbound_form_submission',
                        'page_id' => (isset($entry['id']) ? $entry['id'] : ''),
                        'variation_id' => (isset($entry['variation']) ? $entry['variation'] : ''),
                        'lead_id' => $lead->ID,
                        'datetime' => (isset($entry['datetime']) ? $entry['datetime'] : null)
                    ));
                }
            endif;

            /* import cta clicks into inbound_events table */
            $cta_clicks = get_post_meta($lead->ID, 'call_to_action_clicks', true);
            if ($cta_clicks):

                $cta_clicks = json_decode($cta_clicks, true);

                foreach ($cta_clicks as $entry) {
                    Inbound_Events::store_event(array(
                        'event_name' => 'inbound_cta_click',
                        'cta_id' => (isset($entry['id']) ? $entry['id'] : ''),
                        'variation_id' => (isset($entry['variation']) ? $entry['variation'] : ''),
                        'lead_id' => $lead->ID,
                        'datetime' => (isset($entry['datetime']) ? $entry['datetime'] : null)
                    ));
                }

            endif;

            /* import custom events into inbound_events table */
            $custom_events = get_post_meta($lead->ID, 'inbound_custom_events', true);
            if ($custom_events):

                $custom_events = json_decode($custom_events, true);

                foreach ($custom_events as $entry) {

                    $date_raw = new DateTime($entry['datetime']);
                    $clean_date = $date_raw->format('Y-m-d H:i:s');

                    Inbound_Events::store_event(array(
                        'event_name' => $entry['event_type'],
                        'cta_id' => (isset($entry['id']) ? $entry['id'] : ''),
                        'variation_id' => (isset($entry['variation']) ? $entry['variation'] : ''),
                        'lead_id' => $lead->ID,
                        'session_id' => (isset($entry['tracking_id']) ? $entry['tracking_id'] : ''),
                        'datetime' => $clean_date
                    ));
                }

            endif;
        }

        /* update batch data with next job */
        $args['offset'] = $args['offset'] + 1;
        update_option('leads_batch_processing' , $args );

        /* redirect page */
        ?>
        <script type="text/javascript">
            document.location.href = "edit.php?post_type=wp-lead&page=leads-batch-processing";
        </script>
        <?php
    }


    /**
     * Loops through inbound_events table and if records exists updates them
     */
    public static function import_events_table_072016( $args ) {

        global $wpdb;
        $total = $wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.'inbound_events WHERE funnel <> "" AND funnel <> ""');
        $pages = ceil( $total / $args['posts_per_page'] );

        /* offset for custom queries is slightly different, increment it */
        $args['offset'] = ($args['offset']) ? $args['offset'] : $args['offset'] + 1;

        /* let the user know the processing status */
        echo  sprintf( __(  '%s of %s steps complete. Please wait...' , 'inbound-pro' ) , $args['offset'] , $pages );

        $next = $args['offset'] * $args['posts_per_page'];
        $events = $wpdb->get_results( 'SELECT * FROM '.$wpdb->prefix.'inbound_events WHERE funnel <> "" AND funnel <> "[]" ORDER BY id ASC LIMIT '.$args['offset'].' , '. $next , ARRAY_A );



        /* if all leads are processed echo complete and delete batch job */
        if (!$events || $args['offset'] > $pages ) {
            self::delete_flag();
            echo '<br>';
            _e( 'All done!' , 'inbound-pro' );
            exit;
        }

        echo '<br><br>';
        echo '<img src="'.admin_url('images/spinner-2x.gif').'">';

        foreach ($events as $key => $event ) {
            $event['funnel'] = json_decode($event['funnel'] , true);
            $event['funnel'] = array_keys($event['funnel']);
            $event['funnel'] = json_encode($event['funnel']);
            $wpdb->update( $wpdb->prefix.'inbound_events' , $event , array('id' => $event['id']) );
        }

        /* update batch data with next job */
        $args['offset'] = $args['offset'] + 1;
        update_option('leads_batch_processing' , $args );

        /* redirect page */
        ?>
        <script type="text/javascript">
            document.location.href = "edit.php?post_type=wp-lead&page=leads-batch-processing";
        </script>
        <?php
    }

    /**
     * Loops through all leads and imports events stored in metapairs into inbound_events table
     */
    public static function import_event_data_07132016( $args ) {

        $total = wp_count_posts('wp-lead');
        $pages = ceil( $total->publish / $args['posts_per_page'] );

        /* let the user know the processing status */
        self::get_leads( $args );
        echo  sprintf( __(  '%s of %s steps complete. Please wait...' , 'inbound-pro' ) , $args['offset'] , $pages );


        /* if all leads are processed echo complete and delete batch job */
        if (!self::$leads || $args['offset'] > $pages ) {
            self::delete_flag();
            echo '<br>';
            _e( 'All done!' , 'inbound-pro' );
            exit;
        }

        echo '<br><br>';
        echo '<img src="'.admin_url('images/spinner-2x.gif').'">';

        foreach (self::$leads as $ID => $lead) {

            /* import form submission events into inbound_events table */
            $page_views = get_post_meta($lead->ID, 'page_views', true);

            if (!$page_views) {
                continue;
            }
            $session_id = uniqid();
            $page_views = json_decode($page_views, true);

            foreach ($page_views as $page_id => $times) {

                if (!$page_id || !is_numeric($page_id) ) {
                    continue;
                }

                foreach ($times as $key => $time) {
                    /* assume the rest are form submissions */
                    Inbound_Events::store_page_view(array(
                        'page_id' => $page_id,
                        'variation_id' => 0,
                        'session_id' => $session_id,
                        'lead_id' => $lead->ID,
                        'lead_uid' => '',
                        'datetime' => $time
                    ));
                }
            }
        }

        /* update batch data with next job */
        $args['offset'] = $args['offset'] + 1;
        update_option('leads_batch_processing' , $args );

        /* redirect page */
        ?>
        <script type="text/javascript">
            document.location.href = "edit.php?post_type=wp-lead&page=leads-batch-processing";
        </script>
        <?php
    }

}

new Leads_Batch_Processor();