<?php

$pandatask_last_allowed_html = array();
$pandatask_wpautop_calls     = 0;

function wp_kses_allowed_html( $context ) {
    return array(
        'p'      => array(),
        'strong' => array(),
        'em'     => array(),
        'a'      => array( 'href' => true ),
    );
}

function wp_kses( $value, $allowed_html ) {
    global $pandatask_last_allowed_html;
    $pandatask_last_allowed_html = $allowed_html;
    return (string) $value;
}

function wpautop( $value ) {
    global $pandatask_wpautop_calls;
    ++$pandatask_wpautop_calls;
    return '<p>AUTO:' . $value . '</p>';
}

function wp_strip_all_tags( $value, $remove_breaks = false ) {
    $text = strip_tags( (string) $value );
    return $remove_breaks ? preg_replace( '/[\r\n\t ]+/', ' ', $text ) : $text;
}

function wp_trim_words( $text, $word_limit = 55, $more = null ) {
    $words = preg_split( '/\s+/', trim( (string) $text ) );
    if ( count( $words ) <= $word_limit ) {
        return trim( (string) $text );
    }

    return implode( ' ', array_slice( $words, 0, $word_limit ) ) . ( null === $more ? '&hellip;' : $more );
}

require_once dirname( __DIR__ ) . '/src/Application/Task/TaskDescriptionService.php';

use Pandatask\Application\Task\TaskDescriptionService;

$failures = array();
$assert_same = static function ( $expected, $actual, $message ) use ( &$failures ) {
    if ( $expected !== $actual ) {
        $failures[] = $message . ' Expected ' . var_export( $expected, true ) . ', received ' . var_export( $actual, true ) . '.';
    }
};
$assert_true = static function ( $actual, $message ) use ( &$failures ) {
    if ( true !== $actual ) {
        $failures[] = $message;
    }
};

$canonical = '<figure class="iarf-mermaid"><div class="iarf-mermaid-header"><strong class="iarf-mermaid-title">Review flow</strong></div><div class="iarf-mermaid-stage"><pre class="iarf-mermaid-source"><code class="language-mermaid">flowchart LR\nA --&gt; B</code></pre></div></figure>';
$assert_same( $canonical, TaskDescriptionService::render( $canonical ), 'Canonical block HTML must not be rewritten by wpautop().' );
$assert_same( 0, $pandatask_wpautop_calls, 'Block markup unexpectedly invoked wpautop().' );
$assert_same( '<p>AUTO:Legacy <strong>inline</strong> text</p>', TaskDescriptionService::render( 'Legacy <strong>inline</strong> text' ), 'Legacy inline content should retain paragraph formatting.' );
$assert_same( 1, $pandatask_wpautop_calls, 'Legacy inline content should invoke wpautop exactly once.' );

TaskDescriptionService::sanitize( $canonical );
$assert_true( isset( $pandatask_last_allowed_html['figure']['class'] ), 'Canonical Mermaid figure class must be permitted by the sanitizer contract.' );
$assert_true( isset( $pandatask_last_allowed_html['code']['class'] ), 'Code language classes must be permitted by the sanitizer contract.' );
$assert_true( isset( $pandatask_last_allowed_html['td']['colspan'] ), 'Table cell span metadata must survive sanitization.' );

$excerpt = TaskDescriptionService::plainText( 'Before ' . $canonical . ' after.' );
$assert_same( 'Before [Diagram: Review flow] after.', $excerpt, 'Plain-text excerpts should label diagrams without exposing Mermaid source.' );
$assert_true( false === strpos( $excerpt, 'flowchart' ), 'Mermaid source leaked into the plain-text excerpt.' );
$assert_same( true, TaskDescriptionService::hasBlockMarkup( '<pre><code>example</code></pre>' ), 'Code blocks should be recognized as canonical block markup.' );
$assert_same( false, TaskDescriptionService::hasBlockMarkup( 'inline <strong>text</strong>' ), 'Inline-only legacy HTML should remain eligible for wpautop().' );
$assert_same( '', TaskDescriptionService::notificationExcerpt( '' ), 'Empty descriptions should produce no notification excerpt.' );
$assert_same( 'Short linked description.', TaskDescriptionService::notificationExcerpt( '<p>Short <a href="https://example.test">linked</a> description.</p>' ), 'Short rich descriptions should become safe plain text.' );
$assert_same( 'one two three…', TaskDescriptionService::notificationExcerpt( 'one two three four five', 3 ), 'Long notification excerpts should be bounded with an ellipsis.' );

if ( ! empty( $failures ) ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

echo "Task description tests passed.\n";
