<?php
/**
 * Title: Generative Showcase
 * Slug: cyberdeck/generative-showcase
 * Description: Three live generative pieces side by side, each with its own algorithm and seed. Needs no data.
 * Categories: cyberdeck, featured
 * Keywords: generative, showcase, gallery, algorithms, columns
 * Viewport Width: 1400
 */
?>
<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide"><!-- wp:heading {"className":"is-style-prompt"} -->
<h2 class="wp-block-heading is-style-prompt">algorithms</h2>
<!-- /wp:heading -->

<!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:cyberdeck/generative {"sketch":"truchet","seed":"tiles-01","palette":"duo","minHeight":42,"dim":15} -->
<!-- wp:paragraph {"fontSize":"sm"} -->
<p class="has-sm-font-size">truchet / tiles-01</p>
<!-- /wp:paragraph -->
<!-- /wp:cyberdeck/generative --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:cyberdeck/generative {"sketch":"constellation","seed":"nodes-07","minHeight":42,"dim":10} -->
<!-- wp:paragraph {"fontSize":"sm"} -->
<p class="has-sm-font-size">constellation / nodes-07</p>
<!-- /wp:paragraph -->
<!-- /wp:cyberdeck/generative --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:cyberdeck/generative {"sketch":"glitch","seed":"noise-42","palette":"primary","minHeight":42,"dim":10} -->
<!-- wp:paragraph {"fontSize":"sm"} -->
<p class="has-sm-font-size">glitch / noise-42</p>
<!-- /wp:paragraph -->
<!-- /wp:cyberdeck/generative --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->
