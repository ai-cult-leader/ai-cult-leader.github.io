<?php

// Store Brevo key in wp_options (run once)
add_action('init', function() {
    if (!get_option('al_brevo_key')) {
        update_option('al_brevo_key', 'xkeysib-REPLACEME');
    }
}, 0);

/**
 * Plugin Name: AL Core Functions
 * Description: Core REST endpoints, SEO meta, and features for AnchorageList.com
 * Version: 2.0
 * Must-use plugin — do not delete
 */

// ── Newsletter subscriber table (create if not exists) ────────────────────
add_action('init', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'al_subscribers';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
        $charset = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(191) NOT NULL,
            name VARCHAR(100) DEFAULT '',
            status VARCHAR(20) DEFAULT 'active',
            subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            unsubscribe_token VARCHAR(64) DEFAULT '',
            PRIMARY KEY (id),
            UNIQUE KEY email (email)
        ) $charset");
    }
}, 1);

// ── REST API endpoints ────────────────────────────────────────────────────
add_action('rest_api_init', function() {
    global $wpdb;

    // Subscribe
    register_rest_route('al/v1', '/subscribe', ['methods'=>'POST','callback'=>function($req) use ($wpdb) {
        $table = $wpdb->prefix . 'al_subscribers';
        $email = sanitize_email($req->get_param('email'));
        if (!is_email($email)) return new WP_Error('invalid','Invalid email',['status'=>400]);
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE email=%s",$email));
        if ($existing) return ['success'=>true,'message'=>"You're already subscribed!"];
        $token = wp_generate_password(32,false);
        $wpdb->insert($table,['email'=>$email,'status'=>'active','unsubscribe_token'=>$token,'subscribed_at'=>current_time('mysql')]);
        return ['success'=>true,'message'=>"You're in! Welcome to the Anchorage List newsletter."];
    },'permission_callback'=>'__return_true']);

    // Subscriber count
    register_rest_route('al/v1', '/subscribers/count', ['methods'=>'GET','callback'=>function() use ($wpdb) {
        $table = $wpdb->prefix . 'al_subscribers';
        $count = $wpdb->get_var("SHOW TABLES LIKE '$table'") ? $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status='active'") : 0;
        return ['count'=>intval($count)];
    },'permission_callback'=>'__return_true']);

    // Subscriber list (admin only)
    register_rest_route('al/v1', '/subscribers', ['methods'=>'GET','callback'=>function() use ($wpdb) {
        $table = $wpdb->prefix . 'al_subscribers';
        return $wpdb->get_results("SELECT id,email,name,status,subscribed_at,unsubscribe_token FROM $table WHERE status='active' ORDER BY subscribed_at DESC");
    },'permission_callback'=>function(){return current_user_can('manage_options');}]);

    // Unsubscribe
    register_rest_route('al/v1', '/unsubscribe', ['methods'=>'GET','callback'=>function($req) use ($wpdb) {
        $table = $wpdb->prefix . 'al_subscribers';
        $token = sanitize_text_field($req->get_param('token'));
        $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE unsubscribe_token=%s",$token));
        if ($row) { $wpdb->update($table,['status'=>'unsubscribed'],['id'=>$row->id]); return ['success'=>true,'message'=>'Unsubscribed.']; }
        return new WP_Error('not_found','Token not found',['status'=>404]);
    },'permission_callback'=>'__return_true']);

    // Create listing
    register_rest_route('al/v1', '/create-listing', ['methods'=>'POST','callback'=>function($req) {
        $title = sanitize_text_field($req->get_param('title'));
        $content = wp_kses_post($req->get_param('content') ?? '');
        $category_id = intval($req->get_param('category_id'));
        if (empty($title)) return new WP_Error('no_title','Title required',['status'=>400]);
        $existing = get_page_by_title($title, OBJECT, 'hp_listing');
        if ($existing) return ['success'=>true,'id'=>$existing->ID,'status'=>'duplicate','link'=>get_permalink($existing->ID)];
        $post_id = wp_insert_post(['post_title'=>$title,'post_content'=>$content,'post_type'=>'hp_listing','post_status'=>'publish']);
        if (is_wp_error($post_id)) return ['success'=>false,'error'=>$post_id->get_error_message()];
        if ($category_id) wp_set_post_terms($post_id,[$category_id],'hp_listing_category');
        $fields = ['address'=>'hp_location','phone'=>'hp_phone','website'=>'hp_website','hours'=>'hp_hours'];
        foreach ($fields as $param => $meta) { $val = $req->get_param($param); if ($val) update_post_meta($post_id,$meta,sanitize_text_field($val)); }
        if ($req->get_param('lat')) update_post_meta($post_id,'hp_latitude',floatval($req->get_param('lat')));
        if ($req->get_param('lon')) update_post_meta($post_id,'hp_longitude',floatval($req->get_param('lon')));
        return ['success'=>true,'id'=>$post_id,'status'=>'created','link'=>get_permalink($post_id)];
    },'permission_callback'=>function(){return current_user_can('manage_options');}]);

    // Set listing featured image
    register_rest_route('al/v1', '/set-listing-image', ['methods'=>'POST','callback'=>function($req) {
        $post_id = intval($req->get_param('post_id'));
        $image_url = esc_url_raw($req->get_param('image_url') ?? '');
        if (!$post_id || !$image_url) return new WP_Error('missing','post_id and image_url required',['status'=>400]);
        require_once(ABSPATH.'wp-admin/includes/image.php');
        require_once(ABSPATH.'wp-admin/includes/file.php');
        require_once(ABSPATH.'wp-admin/includes/media.php');
        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) return ['success'=>false,'error'=>$tmp->get_error_message()];
        $name = sanitize_file_name(basename(parse_url($image_url,PHP_URL_PATH))) ?: $post_id.'.jpg';
        $file_array = ['name'=>$name,'tmp_name'=>$tmp];
        $media_id = media_handle_sideload($file_array,$post_id,get_the_title($post_id));
        if (is_wp_error($media_id)) { @unlink($tmp); return ['success'=>false,'error'=>$media_id->get_error_message()]; }
        set_post_thumbnail($post_id,$media_id);
        return ['success'=>true,'media_id'=>$media_id,'post_id'=>$post_id];
    },'permission_callback'=>function(){return current_user_can('manage_options');}]);

    // Listings without images
    register_rest_route('al/v1', '/listings-no-image', ['methods'=>'GET','callback'=>function($req) {
        $page = intval($req->get_param('page') ?? 1);
        $limit = intval($req->get_param('limit') ?? 50);
        $posts = get_posts(['post_type'=>'hp_listing','post_status'=>'publish','posts_per_page'=>$limit,'paged'=>$page,'meta_query'=>[['key'=>'_thumbnail_id','compare'=>'NOT EXISTS']]]);
        $total = (new WP_Query(['post_type'=>'hp_listing','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','meta_query'=>[['key'=>'_thumbnail_id','compare'=>'NOT EXISTS']]]))->found_posts;
        return ['total'=>$total,'page'=>$page,'results'=>array_map(function($p){return ['id'=>$p->ID,'title'=>$p->post_title,'website'=>get_post_meta($p->ID,'hp_website',true),'phone'=>get_post_meta($p->ID,'hp_phone',true),'address'=>get_post_meta($p->ID,'hp_location',true),'cats'=>wp_get_post_terms($p->ID,'hp_listing_category',['fields'=>'slugs'])];}, $posts)];
    },'permission_callback'=>function(){return current_user_can('manage_options');}]);

    // Send single email via Brevo
    register_rest_route('al/v1', '/send-email', ['methods'=>'POST','callback'=>function($req) {
        $to = sanitize_email($req->get_param('to'));
        $subject = sanitize_text_field($req->get_param('subject'));
        $html = wp_kses_post($req->get_param('html'));
        $r = wp_remote_post('https://api.brevo.com/v3/smtp/email',['timeout'=>30,'headers'=>['api-key'=>'' . get_option('al_brevo_key') . '','Content-Type'=>'application/json','Accept'=>'application/json'],'body'=>json_encode(['sender'=>['name'=>'Anchorage List','email'=>'hulljessej@gmail.com'],'to'=>[['email'=>$to]],'subject'=>$subject,'htmlContent'=>$html,'replyTo'=>['email'=>'hulljessej@gmail.com','name'=>'Anchorage List']])]);
        $code = wp_remote_retrieve_response_code($r);
        return ['success'=>$code===201,'to'=>$to];
    },'permission_callback'=>function(){return current_user_can('manage_options');}]);

    // Send newsletter bulk via Brevo
    register_rest_route('al/v1', '/send-newsletter', ['methods'=>'POST','callback'=>function($req) use ($wpdb) {
        $table = $wpdb->prefix . 'al_subscribers';
        $subject = sanitize_text_field($req->get_param('subject'));
        $html = wp_kses_post($req->get_param('html'));
        $batch = intval($req->get_param('batch') ?? 0);
        $size = 50;
        $subs = $wpdb->get_results($wpdb->prepare("SELECT email,name,unsubscribe_token FROM $table WHERE status='active' LIMIT %d OFFSET %d",$size,$batch*$size));
        $sent=0; $failed=0;
        foreach ($subs as $sub) {
            $unsub = home_url('/wp-json/al/v1/unsubscribe?token='.$sub->unsubscribe_token);
            $p = str_replace(['{{UNSUB_URL}}','{{NAME}}'],[$unsub,$sub->name ?: 'Neighbor'],$html);
            $r = wp_remote_post('https://api.brevo.com/v3/smtp/email',['timeout'=>30,'headers'=>['api-key'=>'' . get_option('al_brevo_key') . '','Content-Type'=>'application/json'],'body'=>json_encode(['sender'=>['name'=>'Anchorage List','email'=>'hulljessej@gmail.com'],'to'=>[['email'=>$sub->email,'name'=>$sub->name]],'subject'=>$subject,'htmlContent'=>$p])]);
            wp_remote_retrieve_response_code($r)===201 ? $sent++ : $failed++;
            usleep(100000);
        }
        return ['sent'=>$sent,'failed'=>$failed,'batch'=>$batch,'has_more'=>count($subs)===$size];
    },'permission_callback'=>function(){return current_user_can('manage_options');}]);

    // Add Google/website links to listings in batch
    register_rest_route('al/v1', '/add-listing-links', ['methods'=>'POST','callback'=>function($req) {
        $page = intval($req->get_param('page') ?? 1);
        $per = 50; $updated=0; $skipped=0;
        $posts = get_posts(['post_type'=>'hp_listing','post_status'=>'publish','posts_per_page'=>$per,'paged'=>$page]);
        foreach ($posts as $post) {
            if (strpos($post->post_content,'Google Profile')!==false){$skipped++;continue;}
            $website = get_post_meta($post->ID,'hp_website',true);
            $phone = get_post_meta($post->ID,'hp_phone',true);
            $q = urlencode($post->post_title.' '.(get_post_meta($post->ID,'hp_location',true) ?: 'Anchorage AK'));
            $links = '<div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap">';
            if ($website) $links .= '<a href="'.esc_url($website).'" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;background:#1a73e8;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-size:.88rem;font-weight:700">&#127760; Visit Website</a>';
            $links .= '<a href="https://www.google.com/search?q='.urlencode($post->post_title.' Anchorage Alaska').'" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;background:#fff;color:#1a73e8;border:1.5px solid #1a73e8;padding:10px 18px;border-radius:8px;text-decoration:none;font-size:.88rem;font-weight:700">&#128205; Google Profile</a>';
            if ($phone) $links .= '<a href="tel:'.esc_attr($phone).'" style="display:inline-flex;align-items:center;gap:6px;background:#00c896;color:#000;padding:10px 18px;border-radius:8px;text-decoration:none;font-size:.88rem;font-weight:700">&#128222; Call Now</a>';
            $links .= '</div>';
            wp_update_post(['ID'=>$post->ID,'post_content'=>$post->post_content.$links]);
            $updated++;
        }
        return ['updated'=>$updated,'skipped'=>$skipped,'page'=>$page,'has_more'=>count($posts)===$per];
    },'permission_callback'=>function(){return current_user_can('manage_options');}]);
});

// ── SEO meta on listing pages ─────────────────────────────────────────────
add_action('wp_head', function() {
    if (!is_singular('hp_listing')) return;
    global $post;
    $title   = get_the_title();
    $address = get_post_meta($post->ID,'hp_location',true);
    $phone   = get_post_meta($post->ID,'hp_phone',true);
    $cats    = wp_get_post_terms($post->ID,'hp_listing_category');
    $cat     = !empty($cats) ? $cats[0]->name : 'Local Business';
    $desc    = substr($title.' — '.$cat.' in Anchorage, Alaska.'.($address?' Located at '.$address.'.':'').($phone?' Call '.$phone.'.':'').' Find reviews, hours and directions on AnchorageList.com.',0,160);
    $img     = has_post_thumbnail() ? get_the_post_thumbnail_url($post->ID,'large') : '';
    $url     = get_permalink();
    echo '<meta name="description" content="'.esc_attr($desc).'">'."\n";
    echo '<meta property="og:title" content="'.esc_attr($title).' | AnchorageList.com">'."\n";
    echo '<meta property="og:description" content="'.esc_attr($desc).'">'."\n";
    echo '<meta property="og:type" content="business.business">'."\n";
    echo '<meta property="og:url" content="'.esc_url($url).'">'."\n";
    if ($img) echo '<meta property="og:image" content="'.esc_url($img).'">'."\n";
    $schema = ['@context'=>'https://schema.org','@type'=>'LocalBusiness','name'=>$title,'address'=>['@type'=>'PostalAddress','streetAddress'=>$address?:'','addressLocality'=>'Anchorage','addressRegion'=>'AK','addressCountry'=>'US'],'url'=>$url];
    if ($phone) $schema['telephone'] = $phone;
    if ($img)   $schema['image']     = $img;
    $lat = get_post_meta($post->ID,'hp_latitude',true);
    $lon = get_post_meta($post->ID,'hp_longitude',true);
    if ($lat && $lon) $schema['geo'] = ['@type'=>'GeoCoordinates','latitude'=>$lat,'longitude'=>$lon];
    echo '<script type="application/ld+json">'.json_encode($schema,JSON_UNESCAPED_SLASHES).'</script>'."\n";
}, 5);

// SEO meta on category pages
add_action('wp_head', function() {
    if (!is_tax('hp_listing_category')) return;
    $term = get_queried_object();
    if (!$term) return;
    $desc = 'Find the best '.$term->name.' in Anchorage, Alaska. Browse '.$term->count.' local listings with reviews, hours, and directions on AnchorageList.com.';
    echo '<meta name="description" content="'.esc_attr(substr($desc,0,160)).'">'."\n";
    echo '<meta property="og:title" content="'.esc_attr($term->name).' in Anchorage | AnchorageList.com">'."\n";
}, 5);

// ── Review CTA + Related listings on every listing page ───────────────────
add_filter('the_content', function($content) {
    if (!is_singular('hp_listing') || !in_the_loop() || !is_main_query()) return $content;
    global $post;
    $title    = get_the_title($post->ID);
    $cats     = wp_get_post_terms($post->ID,'hp_listing_category');
    $cat_id   = !empty($cats) ? $cats[0]->term_id : 0;
    $cat_name = !empty($cats) ? $cats[0]->name : 'Local Business';
    $cat_slug = !empty($cats) ? $cats[0]->slug : '';

    // Review CTA
    if (strpos($content,'al-review-cta') === false) {
        $content .= '<div class="al-review-cta" style="margin-top:32px;background:#f0fdf9;border:2px solid #a7f3d0;border-radius:14px;padding:24px;text-align:center">
  <div style="font-size:1.5rem;margin-bottom:8px">&#11088;&#11088;&#11088;&#11088;&#11088;</div>
  <div style="font-weight:800;font-size:1rem;color:#065f46;margin-bottom:6px">Been to '.esc_html($title).'?</div>
  <p style="color:#555;font-size:.88rem;margin:0 0 16px;line-height:1.5">Share your experience and help other Anchorage locals make great choices.</p>
  <a href="#reviews" style="background:#00c896;color:#000;padding:11px 24px;border-radius:8px;font-weight:800;font-size:.9rem;text-decoration:none;display:inline-block">Write a Review &#x2192;</a>
</div>';
    }

    // Related listings
    if ($cat_id && strpos($content,'al-related') === false) {
        $related = get_posts(['post_type'=>'hp_listing','post_status'=>'publish','posts_per_page'=>4,'post__not_in'=>[$post->ID],'tax_query'=>[['taxonomy'=>'hp_listing_category','field'=>'term_id','terms'=>$cat_id]],'orderby'=>'rand']);
        if ($related) {
            $cards = '';
            foreach ($related as $r) {
                $img_id  = get_post_thumbnail_id($r->ID);
                $img_url = $img_id ? wp_get_attachment_image_url($img_id,'medium') : '';
                $addr    = explode(',', get_post_meta($r->ID,'hp_location',true))[0] ?: 'Anchorage, AK';
                $cards  .= '<a href="'.esc_url(get_permalink($r->ID)).'" style="display:block;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;text-decoration:none;color:#111">';
                $cards  .= $img_url ? '<img src="'.esc_url($img_url).'" style="width:100%;height:130px;object-fit:cover;display:block" loading="lazy" alt="">' : '<div style="width:100%;height:130px;background:linear-gradient(135deg,#0a1628,#0d4a3a);display:flex;align-items:center;justify-content:center;font-size:2rem">&#127956;</div>';
                $cards  .= '<div style="padding:12px"><div style="font-weight:700;font-size:.88rem;color:#111;margin-bottom:3px">'.esc_html($r->post_title).'</div><div style="font-size:.74rem;color:#888">'.esc_html($addr).'</div></div></a>';
            }
            $content .= '<div class="al-related" style="margin-top:36px"><div style="font-weight:800;font-size:1.1rem;color:#111;margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid #e5e7eb">More '.esc_html($cat_name).' in Anchorage</div><div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">'.$cards.'</div><p style="text-align:center;margin-top:16px"><a href="/listing-category/'.esc_attr($cat_slug).'/" style="color:#00c896;font-weight:700;font-size:.88rem;text-decoration:none">View all '.esc_html($cat_name).' &#x2192;</a></p></div>';
        }
    }
    return $content;
}, 20);

// ── Hide leftover header image ─────────────────────────────────────────────
add_action('wp_head', function() {
    echo '<style>img[src*="southside"],img[alt*="Jiu-Jitsu"]{display:none!important}</style>';
}, 1);
