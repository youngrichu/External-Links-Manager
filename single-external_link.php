<?php
$url = get_post_meta(get_the_ID(), '_external_link_url', true);
if (!empty($url)) {
    wp_redirect($url, 302);
    exit;
}
?>