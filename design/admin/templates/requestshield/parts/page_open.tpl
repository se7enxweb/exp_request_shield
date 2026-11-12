{* Opening of every Setup > Request shield page in the classic admin design: its box frame with the title, then the
   tabs and the intro. Input: $view, $title, $intro (optional). Closed by requestshield/parts/page_close.tpl.
   design/admin carries a copy of components.css, so the body's .a4-* components look the same here. *}
<div class="context-block a4-page a4-rs-page">

<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">
<h1 class="context-title">{$title|wash}</h1>
<div class="header-mainline"></div>
</div></div></div></div></div></div>

<div class="box-ml"><div class="box-mr"><div class="box-content">

{include uri='design:requestshield/parts/tabs.tpl' view=$view}

{if is_set( $intro )}<p class="a4-intro">{$intro|wash}</p>{/if}
