<?php

/* === START MODEL RATING BAR === */

/* --- Getters --- */
function tmw_get_model_views($post_id = null) {
    $post_id = $post_id ?: get_the_ID();
    return (int) get_post_meta($post_id, '_model_views', true);
}
function tmw_get_model_likes($post_id = null) {
    $post_id = $post_id ?: get_the_ID();
    return (int) get_post_meta($post_id, '_model_like_count', true);
}
function tmw_get_model_dislikes($post_id = null) {
    $post_id = $post_id ?: get_the_ID();
    return (int) get_post_meta($post_id, '_model_dislike_count', true);
}

/* --- Increment view counter on each model page view --- */
add_action('wp', function () {
    if (is_singular('model')) {
        $id = get_the_ID();
        $views = tmw_get_model_views($id);
        update_post_meta($id, '_model_views', $views + 1);
    }
});

/* --- Output identical rating bar --- */
function tmw_output_model_rating_bar($post_id = null) {
    $post_id = $post_id ?: get_the_ID();

    $views    = tmw_get_model_views($post_id);
    $likes    = tmw_get_model_likes($post_id);
    $dislikes = tmw_get_model_dislikes($post_id);
    $total    = max(1, $likes + $dislikes);
    $percent  = round(($likes / $total) * 100);
    ?>
    <div class="tmw-rating-wrapper">
        <div class="tmw-views"><?php echo esc_html($views); ?> views</div>
        <div class="tmw-rating-bar">
            <div class="tmw-rating-fill" style="width:<?php echo esc_attr($percent); ?>%;"></div>
        </div>
        <div class="tmw-rating-icons">
            <span class="like"><i class="fa fa-thumbs-up"></i> <?php echo esc_html($likes); ?></span>
            <span class="dislike"><i class="fa fa-thumbs-down"></i> <?php echo esc_html($dislikes); ?></span>
            <span class="percent"><?php echo esc_html($percent); ?>%</span>
        </div>
    </div>
    <?php
}
/* === END MODEL RATING BAR === */
