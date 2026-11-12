{* Opening of every Setup > Request shield page in this design: the title, the tabs and the intro.
   Input: $view, $title, $intro (optional). Closed by requestshield/parts/page_close.tpl.
   The body is built from the administration's shared components (components.css, .a4-*) and
   stylesheets/modules/requestshield.css (.a4-rs-*), so it follows the light and dark mode. *}
<div class="context-block a4-page a4-rs-page">

<div class="box-header"><div class="box-ml">
<h1 class="context-title">{$title|wash}</h1>
</div></div>

<div class="box-bc"><div class="box-ml"><div class="box-content">

{include uri='design:requestshield/parts/tabs.tpl' view=$view}

{if is_set( $intro )}<p class="a4-intro">{$intro|wash}</p>{/if}
