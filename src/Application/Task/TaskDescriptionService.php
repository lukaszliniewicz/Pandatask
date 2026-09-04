<?php

namespace Pandatask\Application\Task;

/**
 * Owns the canonical task-description HTML contract.
 *
 * Task descriptions are stored as sanitized HTML. Legacy plain-text or inline-only
 * descriptions keep their historical paragraph formatting at render time, while
 * canonical block HTML is returned without wpautop() mutation so code blocks,
 * tables, and Mermaid figures round-trip losslessly.
 */
final class TaskDescriptionService {

    /**
     * @param mixed $description Raw task description.
     * @return string
     */
    public static function sanitize( $description ) {
        $allowed = wp_kses_allowed_html( 'post' );
        $class_tags = array( 'figure', 'div', 'pre', 'code', 'strong', 'span', 'figcaption', 'table', 'thead', 'tbody', 'tr', 'th', 'td' );

        foreach ( $class_tags as $tag ) {
            if ( ! isset( $allowed[ $tag ] ) || ! is_array( $allowed[ $tag ] ) ) {
                $allowed[ $tag ] = array();
            }
            $allowed[ $tag ]['class'] = true;
        }

        foreach ( array( 'th', 'td' ) as $tag ) {
            $allowed[ $tag ]['colspan'] = true;
            $allowed[ $tag ]['rowspan'] = true;
            $allowed[ $tag ]['scope']   = true;
        }

        return wp_kses( (string) $description, $allowed );
    }

    /**
     * @param mixed $description Stored task description.
     * @return string
     */
    public static function render( $description ) {
        $sanitized = self::sanitize( $description );

        if ( '' === trim( $sanitized ) ) {
            return '';
        }

        if ( self::hasBlockMarkup( $sanitized ) ) {
            return $sanitized;
        }

        return self::sanitize( wpautop( $sanitized ) );
    }

    /**
     * Convert rich task content into a short plain-text representation suitable
     * for notifications and other excerpts. Mermaid source is intentionally
     * replaced with a diagram label rather than exposed verbatim.
     *
     * @param mixed $description Rich task description.
     * @return string
     */
    public static function plainText( $description ) {
        $html = (string) $description;
        $html = preg_replace_callback(
            '/<figure\b[^>]*class=(?:"[^"]*\biarf-mermaid\b[^"]*"|\'[^\']*\biarf-mermaid\b[^\']*\')[^>]*>[\s\S]*?<\/figure>/i',
            static function ( $matches ) {
                $figure = $matches[0];
                $title  = '';

                if ( preg_match( '/<[^>]*class=(?:"[^"]*\biarf-mermaid-title\b[^"]*"|\'[^\']*\biarf-mermaid-title\b[^\']*\')[^>]*>([\s\S]*?)<\//i', $figure, $title_match ) ) {
                    $title = trim( wp_strip_all_tags( html_entity_decode( $title_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
                }

                return '' !== $title ? '[Diagram: ' . $title . ']' : '[Diagram]';
            },
            $html
        );

        $text = html_entity_decode( wp_strip_all_tags( (string) $html, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/\s+/u', ' ', $text );

        return trim( (string) $text );
    }

    /**
     * Build a bounded, plain-text description excerpt for notifications.
     *
     * @param mixed $description Rich task description.
     * @param int   $word_limit  Maximum number of words to retain.
     * @return string
     */
    public static function notificationExcerpt( $description, $word_limit = 30 ) {
        $plain_text = self::plainText( $description );

        if ( '' === $plain_text ) {
            return '';
        }

        return wp_trim_words( $plain_text, max( 1, (int) $word_limit ), '…' );
    }

    /**
     * @param string $html Sanitized HTML.
     * @return bool
     */
    public static function hasBlockMarkup( $html ) {
        return 1 === preg_match( '/<(?:p|h[1-6]|ul|ol|li|blockquote|pre|table|figure|div|hr)\b/i', (string) $html );
    }
}
