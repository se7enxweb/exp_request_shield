{* The tabs between the pages of Setup > Request shield. Input: $view (dashboard, log, rules). *}
<nav class="a4-rs-nav" aria-label="{'Request shield'|i18n( 'extension/exp_request_shield' )|wash}">
<ul class="a4-tabs">
    {foreach hash( 'dashboard', 'Dashboard'|i18n( 'extension/exp_request_shield' ),
                   'log', 'Log'|i18n( 'extension/exp_request_shield' ),
                   'rules', 'Rules'|i18n( 'extension/exp_request_shield' ) ) as $rs_key => $rs_label}
    <li><a href={concat( '/requestshield/', $rs_key )|ezurl}{if eq( $view, $rs_key )} class="current" aria-current="page"{/if}>{$rs_label|wash}</a></li>
    {/foreach}
</ul>
</nav>
