{* Opening of every Setup > Request shield page in exp_adminui's adminui design: the page header and the panel of
   its setup pages, then the tabs and the intro. Input: $view, $title, $intro (optional). Closed by
   requestshield/parts/page_close.tpl. adminui loads the shared components (AdminComponentCSSFileList) and the
   extensions' BackendCSSFileList, so the body's .a4-* and .a4-rs-* classes work here. *}
<div class="context-block a4-page a4-rs-page">

<div class="box-header">
    <h1 class="context-title">{$title|wash}</h1>
    <div class="header-mainline"></div>
</div>

<div class="box-content panel">

{include uri='design:requestshield/parts/tabs.tpl' view=$view}

{if is_set( $intro )}<p class="a4-intro">{$intro|wash}</p>{/if}
