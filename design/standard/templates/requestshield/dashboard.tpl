{* Setup > Request shield (requestshield/dashboard): whether the shield runs and how, and what its log says for the
   last hours -- a stacked bar per hour (refused, checked, watched, other), the rules that decided most and the newest
   lines. The page changes nothing; the shield is set up in its rule files (doc/configuration.md).

   Variables: status (expRequestShield::status()), activity (expRequestShield::activity()), chart
   (expRequestShieldAdmin::chart(): the bars as rects with their place in the viewBox), view.
   The design's frame is requestshield/parts/page_open.tpl and page_close.tpl (admin, admin4, adminui, standard). *}
{def $rs_group_names = hash( 'refused', 'Refused'|i18n( 'extension/exp_request_shield' ),
                             'checked', 'Browser check'|i18n( 'extension/exp_request_shield' ),
                             'watched', 'Watched (monitor)'|i18n( 'extension/exp_request_shield' ),
                             'other', 'Other'|i18n( 'extension/exp_request_shield' ) )
     $rs_mode_names = hash( 'off', 'Off'|i18n( 'extension/exp_request_shield' ),
                            'monitor', 'Monitor'|i18n( 'extension/exp_request_shield' ),
                            'enforce', 'Enforce'|i18n( 'extension/exp_request_shield' ),
                            'strict', 'Strict'|i18n( 'extension/exp_request_shield' ) )
     $rs_source_names = hash( 'constant', 'named in config.php (EXP_REQUEST_SHIELD_RULES)'|i18n( 'extension/exp_request_shield' ),
                              'site', 'the site\'s own file in settings/request-shield/'|i18n( 'extension/exp_request_shield' ),
                              'extension', 'the extension\'s default file (no site file yet)'|i18n( 'extension/exp_request_shield' ) )}
{include uri='design:requestshield/parts/page_open.tpl' view=$view title='Request shield'|i18n( 'extension/exp_request_shield' )
         intro='A filter in front of the site: it decides every request before the kernel starts, turns away scanners, floods and stray forms, and makes bots pay with a browser check.'|i18n( 'extension/exp_request_shield' )}

{if $flash}
<div class="a4-notice {if $flash.ok}is-ok{else}is-bad{/if}" role="{if $flash.ok}status{else}alert{/if}"><p>{$flash.text|wash}</p></div>
{/if}

{if $can_manage}
<section class="a4-card a4-rs-controls" aria-labelledby="rs-controls-title">
    <div class="a4-card-body">
    <h2 class="a4-sr-only" id="rs-controls-title">{'Switches'|i18n( 'extension/exp_request_shield' )}</h2>
    <form method="post" action={'/requestshield/switch'|ezurl} class="a4-rs-switches">
        <div class="a4-rs-switch">
            <span class="a4-label">{'Shield'|i18n( 'extension/exp_request_shield' )}</span>
            {if $status.paused}
            <span class="a4-badge a4-rs-badge is-refused">{'Paused'|i18n( 'extension/exp_request_shield' )}</span>
            <button type="submit" class="a4-btn a4-btn-primary" name="ResumeButton" value="1">{'Switch on'|i18n( 'extension/exp_request_shield' )}</button>
            {else}
            <span class="a4-badge a4-rs-badge is-on">{'On'|i18n( 'extension/exp_request_shield' )}</span>
            <button type="submit" class="a4-btn" name="PauseButton" value="1">{'Pause'|i18n( 'extension/exp_request_shield' )}</button>
            {/if}
        </div>
        <div class="a4-rs-switch">
            <span class="a4-label" id="rs-mode-label">{'Mode'|i18n( 'extension/exp_request_shield' )}</span>
            <div class="a4-rs-segment" role="group" aria-labelledby="rs-mode-label">
            {foreach $switch_modes as $rs_m}
                <button type="submit" class="a4-btn{if eq( $status.mode, $rs_m )} a4-btn-primary is-current{/if}" name="Mode" value="{$rs_m|wash}" aria-pressed="{if eq( $status.mode, $rs_m )}true{else}false{/if}"{if $mode_target.can|not} disabled="disabled"{/if}>{first_set( $rs_mode_names[$rs_m], $rs_m )|wash}</button>
            {/foreach}
            </div>
        </div>
    </form>
    <p class="a4-help a4-rs-switch-help">
    {if $mode_target.can|not}
        {$mode_target.why|wash}
    {elseif $mode_target.create}
        {'The first mode switch makes the site\'s own rule file from the extension\'s default: %file. Pause takes effect at once; a mode within the rules\' recheck seconds.'|i18n( 'extension/exp_request_shield',, hash( '%file', $mode_target.file ) )|wash}
    {else}
        {'Pause creates %pause; a mode rewrites the "set mode" line of %file, keeping the previous file in the backup directory. Pause takes effect at once; a mode within the rules\' recheck seconds.'|i18n( 'extension/exp_request_shield',, hash( '%pause', concat( $status.var_dir, '/PAUSED' ), '%file', $mode_target.file ) )|wash}
    {/if}
    </p>
    </div>
</section>
{/if}

{if $status.error}
<div class="a4-notice is-bad" role="alert">
    <h2 class="a4-h3 a4-notice-title">{'The rules cannot be read'|i18n( 'extension/exp_request_shield' )}</h2>
    <p>{'The shield lets every request through until this is fixed (it fails open).'|i18n( 'extension/exp_request_shield' )}</p>
    <pre class="a4-pre">{$status.error|wash}</pre>
</div>
{/if}
{if $status.paused}
<div class="a4-notice is-warn" role="status"><p>{'The shield is paused: the file %file exists. Switch it on above, or remove the file.'|i18n( 'extension/exp_request_shield',, hash( '%file', concat( $status.var_dir, '/PAUSED' ) ) )|wash}</p></div>
{/if}
{if $status.hooked|not}
<div class="a4-notice is-warn" role="status">
    <p>{'The shield did not run for this page: config.php does not load the extension\'s hook, or this server does not run it.'|i18n( 'extension/exp_request_shield' )}</p>
    <p><code class="a4-code">if ( is_file( __DIR__ . '/extension/exp_request_shield/bootstrap/hook.php' ) ) require __DIR__ . '/extension/exp_request_shield/bootstrap/hook.php';</code></p>
</div>
{/if}
{if eq( $status.mode, 'monitor' )}
<div class="a4-notice is-info" role="status"><p>{'Monitor mode: every decision is logged as it would be taken, and nobody is refused. When the log has looked right for a few days, set "set mode enforce" in the main rule file.'|i18n( 'extension/exp_request_shield' )}</p></div>
{/if}

<ul class="a4-figures">
    <li class="a4-figure{if eq( $status.mode, 'enforce' )} is-ok{elseif or( eq( $status.mode, 'off' ), $status.error )} is-bad{/if}"><strong>{if $status.mode}{first_set( $rs_mode_names[$status.mode], $status.mode )|wash}{else}&mdash;{/if}</strong><span>{'Mode'|i18n( 'extension/exp_request_shield' )}</span></li>
    <li class="a4-figure"><strong>{$activity.lines}</strong><span>{'Log lines, last %hours h'|i18n( 'extension/exp_request_shield',, hash( '%hours', $activity.hours ) )|wash}</span></li>
    {foreach $rs_group_names as $rs_group => $rs_name}
    <li class="a4-figure a4-rs-figure is-{$rs_group}"><strong>{$activity.groups[$rs_group]}</strong><span>{$rs_name|wash}</span></li>
    {/foreach}
    {* How many of the browser checks above (the checked group) a browser passed; the rest were never answered,
       mostly by bots *}
    <li class="a4-figure" title="{'Checks a browser passed; the rest were never answered, mostly by bots'|i18n( 'extension/exp_request_shield' )|wash}"><strong>{$activity.checks.passed}</strong><span>{'Browser checks passed'|i18n( 'extension/exp_request_shield' )}{if gt( $activity.checks.sent, 0 )} ({$activity.checks.passed|mul( 100 )|div( $activity.checks.sent )|round} %){/if}</span></li>
</ul>

<section class="a4-section" aria-labelledby="rs-chart-title">
<h2 class="a4-h2" id="rs-chart-title">{'Decisions per hour'|i18n( 'extension/exp_request_shield' )}</h2>
{if $activity.lines|gt( 0 )}
<figure class="a4-rs-chart">
    <svg class="a4-rs-chart-svg" viewBox="0 0 {$chart.width} {sum( $chart.height, 22 )}" role="img"
         aria-label="{'Log lines per hour for the last %hours hours, at most %max in one hour'|i18n( 'extension/exp_request_shield',, hash( '%hours', $activity.hours, '%max', $chart.max ) )|wash}">
        <line class="a4-rs-chart-axis" x1="0" y1="{$chart.height}" x2="{$chart.width}" y2="{$chart.height}" />
        {foreach $chart.bars as $rs_bar}
        <g><title>{$rs_bar.time|l10n( 'shortdatetime' )|wash}: {$rs_bar.total}</title>
        {foreach $rs_bar.rects as $rs_rect}<rect class="a4-rs-bar is-{$rs_rect.group}" x="{$rs_rect.x}" y="{$rs_rect.y}" width="{$rs_rect.width}" height="{$rs_rect.height}" />{/foreach}
        </g>
        {/foreach}
        {foreach $chart.ticks as $rs_tick}
        <text class="a4-rs-chart-tick" x="{$rs_tick.x}" y="{sum( $chart.height, 16 )}" text-anchor="middle">{$rs_tick.time|datetime( 'custom', '%H:%i' )}</text>
        {/foreach}
    </svg>
    <figcaption class="a4-rs-legend">
        <span>{'Most in one hour: %max'|i18n( 'extension/exp_request_shield',, hash( '%max', $chart.max ) )|wash}</span>
        {foreach $rs_group_names as $rs_group => $rs_name}<span class="a4-rs-key is-{$rs_group}">{$rs_name|wash}</span>{/foreach}
    </figcaption>
</figure>
{elseif $status.log_file}
<p class="a4-empty">{'The log has no lines for the last %hours hours. With "set log-level flag" the shield writes only what it refuses, checks or would refuse.'|i18n( 'extension/exp_request_shield',, hash( '%hours', $activity.hours ) )|wash}</p>
{else}
<p class="a4-empty">{'The rules name no log file ("set log <file>"), so there is nothing to show.'|i18n( 'extension/exp_request_shield' )}</p>
{/if}
</section>

<div class="a4-grid is-wide">
<section class="a4-card" aria-labelledby="rs-setup-title">
    <div class="a4-card-head"><h2 class="a4-card-title" id="rs-setup-title">{'Setup'|i18n( 'extension/exp_request_shield' )}</h2></div>
    <div class="a4-card-body">
    <dl class="a4-facts">
        <div><dt>{'Library'|i18n( 'extension/exp_request_shield' )}</dt><dd>{if $status.library}request-shield {$status.library.version|wash} <span class="a4-muted">({$status.library.build|wash})</span>{else}&mdash;{/if}</dd></div>
        <div><dt>{'Extension'|i18n( 'extension/exp_request_shield' )}</dt><dd>exp_request_shield {$status.extension_version|wash}</dd></div>
        <div><dt>{'Main rule file'|i18n( 'extension/exp_request_shield' )}</dt><dd><code class="a4-code a4-rs-path">{$status.rule_file|wash}</code><br /><span class="a4-muted">{first_set( $rs_source_names[$status.rule_source], '' )|wash}</span></dd></div>
        <div><dt>{'Store'|i18n( 'extension/exp_request_shield' )}</dt><dd>{if $status.tier}{$status.tier.tier|wash} &middot; {$status.tier.store|wash}{elseif $status.store}{$status.store|wash}{else}&mdash;{/if}{if $status.store_dir}<br /><code class="a4-code a4-rs-path">{$status.store_dir|wash}</code>{/if}</dd></div>
        <div><dt>{'Log'|i18n( 'extension/exp_request_shield' )}</dt><dd>{if $status.log_file}<code class="a4-code a4-rs-path">{$status.log_file|wash}</code> <span class="a4-muted">({$status.log_level|wash})</span>{else}{'none'|i18n( 'extension/exp_request_shield' )}{/if}</dd></div>
        <div><dt>{'Host names'|i18n( 'extension/exp_request_shield' )}</dt><dd>{if $status.hosts|count}{$status.hosts|implode( ', ' )|wash}{else}{'any'|i18n( 'extension/exp_request_shield' )}{/if}</dd></div>
        <div><dt>{'Parts loaded'|i18n( 'extension/exp_request_shield' )}</dt><dd>{if $status.plugins|count}{$status.plugins|implode( ', ' )|wash}{else}{'the core only'|i18n( 'extension/exp_request_shield' )}{/if}</dd></div>
    </dl>
    {if and( $status.tier, $status.tier.off|count )}
    <ul class="a4-rs-notes">{foreach $status.tier.off as $rs_off}<li class="a4-warn-text">{$rs_off|wash}</li>{/foreach}</ul>
    {/if}
    </div>
</section>

<section class="a4-card" aria-labelledby="rs-rules-title">
    <div class="a4-card-head"><h2 class="a4-card-title" id="rs-rules-title">{'Rules that decided most'|i18n( 'extension/exp_request_shield' )}</h2></div>
    <div class="a4-card-body">
    {if $activity.rules|count}
    <div class="a4-table-wrap">
    <table class="a4-table">
    <thead><tr><th scope="col">{'Rule'|i18n( 'extension/exp_request_shield' )}</th><th scope="col" class="a4-num">{'Lines'|i18n( 'extension/exp_request_shield' )}</th><th scope="col">{'Last'|i18n( 'extension/exp_request_shield' )}</th></tr></thead>
    <tbody>
    {foreach $activity.rules|extract( 0, 10 ) as $rs_rule}
    <tr><td><code class="a4-code">{$rs_rule.rule|wash}</code></td><td class="a4-num">{$rs_rule.count}</td><td class="a4-nowrap">{$rs_rule.last|l10n( 'shortdatetime' )}</td></tr>
    {/foreach}
    </tbody>
    </table>
    </div>
    {else}
    <p class="a4-empty">{'No rule has decided anything in this period.'|i18n( 'extension/exp_request_shield' )}</p>
    {/if}
    </div>
</section>
</div>

<section class="a4-section" aria-labelledby="rs-recent-title">
<div class="a4-section-head">
    <h2 class="a4-h2" id="rs-recent-title">{'Newest log lines'|i18n( 'extension/exp_request_shield' )}</h2>
    <a class="a4-btn a4-btn-small" href={'/requestshield/log'|ezurl}>{'Whole log'|i18n( 'extension/exp_request_shield' )}</a>
</div>
{include uri='design:requestshield/parts/log_table.tpl' rows=$activity.recent group_names=$rs_group_names}
</section>

{include uri='design:requestshield/parts/page_close.tpl'}
{undef $rs_group_names $rs_mode_names $rs_source_names}
