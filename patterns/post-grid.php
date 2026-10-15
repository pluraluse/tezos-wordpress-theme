<?php
/**
 * Title: Post Grid
 * Slug: cyberdeck/post-grid
 * Description: Three-column grid of posts with pagination. Inherits the main query.
 * Categories: cyberdeck
 * Keywords: posts, grid, blog, archive
 * Inserter: false
 */
?>
<!-- wp:query {"queryId":0,"query":{"perPage":9,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":true},"align":"wide"} -->
<div class="wp-block-query alignwide"><!-- wp:post-template {"layout":{"type":"grid","columnCount":3}} -->
<!-- wp:group {"className":"is-style-neon-panel","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-neon-panel"><!-- wp:post-featured-image {"isLink":true,"aspectRatio":"16/9","className":"is-style-scanned"} /-->

<!-- wp:post-date {"fontSize":"xs"} /-->

<!-- wp:post-title {"level":3,"isLink":true,"fontSize":"lg"} /-->

<!-- wp:post-excerpt {"excerptLength":22} /--></div>
<!-- /wp:group -->
<!-- /wp:post-template -->

<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"space-between"}} -->
<!-- wp:query-pagination-previous /-->

<!-- wp:query-pagination-numbers /-->

<!-- wp:query-pagination-next /-->
<!-- /wp:query-pagination -->

<!-- wp:query-no-results -->
<!-- wp:paragraph {"className":"is-style-comment"} -->
<p class="is-style-comment">no records found</p>
<!-- /wp:paragraph -->
<!-- /wp:query-no-results --></div>
<!-- /wp:query -->
