<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="profile" href="https://gmpg.org/xfn/11">
    <title><?php esc_html_e( 'Task Board - Fullscreen', 'pandatask' ); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class('pandat69-fullscreen-body'); ?>>
    <?php
    if ( true === $pandatask_fullscreen_access ) {
        echo do_shortcode(
            sprintf(
                '[task_board board_name="%s"]',
                esc_attr( $pandatask_fullscreen_board_name )
            )
        );
    } else {
        $is_authentication_error = 401 === (int) $pandatask_fullscreen_access->get_error_data( 'status' );
        $heading                 = $is_authentication_error
            ? __( 'Sign in required', 'pandatask' )
            : __( 'Access denied', 'pandatask' );

        echo '<main class="pandat69-permission-error">';
        echo '<h1>' . esc_html( $heading ) . '</h1>';
        echo '<p>' . esc_html( $pandatask_fullscreen_access->get_error_message() ) . '</p>';
        echo '</main>';
    }
    ?>
    <?php wp_footer(); ?>
</body>
</html>
