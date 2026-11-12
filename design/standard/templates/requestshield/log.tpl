{* Setup > Request shield > Log (requestshield/log): the shield's log lines of the chosen period, newest first, by
   group, a page at a time. Every choice is a link with view parameters, so the page needs no form and no script.

   Variables: status, activity, rows (this page's lines), hours, hour_choices, group ('' for all), groups, total,
   first, last, prev_url, next_url, view. *}
{def $rs_group_names = hash( 'refused', 'Refused'|i18n( 'extension/exp_request_shield' ),
                             'checked', 'Browser check'|i18n( 'extension/exp_request_shield' ),
                             'watched', 'Watched (monitor)'|i18n( 'extension/exp_request_shield' ),
                             'other', 'Other'|i18n( 'extension/exp_request_shield' ) )
     $rs_base = concat( '/requestshield/log/(hours)/', $hours )}
{include uri='design:requestshield/parts/page_open.tpl' view=$view title='Log'|i18n( 'extension/exp_request_shield' )
         intro='What the shield wrote to its log: every request it refused, sent to the browser check or, in monitor mode, would have refused. Newest first.'|i18n( 'extension/exp_request_shield' )}

{if $status.error}
<div class="a4-notice is-bad" role="alert"><p>{'The rules cannot be read, so the log file is not known:'|i18n( 'extension/exp_request_shield' )} {$status.error|wash}</p></div>
{elseif $status.log_file|not}
<div class="a4-notice is-info"><p>{'The rules name no log file ("set log <file>").'|i18n( 'extension/exp_request_shield' )}</p></div>
{elseif $activity.readable|not}
<div class="a4-notice is-warn"><p>{'The log %file does not exist yet or cannot be read by the web server.'|i18n( 'extension/exp_request_shield',, hash( '%file', $status.log_file ) )|wash}</p></div>
{/if}

<div class="a4-filterbar a4-rs-filter">
    <div class="a4-field">
        <span class="a4-label">{'Period'|i18n( 'extension/exp_request_shield' )}</span>
        <ul class="a4-chips">
        {foreach $hour_choices as $rs_h}
            <li><a class="a4-chip{if eq( $rs_h, $hours )} is-current{/if}" href={concat( '/requestshield/log/(hours)/', $rs_h, cond( $group, concat( '/(group)/', $group ), '' ) )|ezurl}{if eq( $rs_h, $hours )} aria-current="true"{/if}><span>{'%hours h'|i18n( 'extension/exp_request_shield',, hash( '%hours', $rs_h ) )|wash}</span></a></li>
        {/foreach}
        </ul>
    </div>
    <div class="a4-field a4-field-wide">
        <span class="a4-label">{'Decision'|i18n( 'extension/exp_request_shield' )}</span>
        <ul class="a4-chips">
            <li><a class="a4-chip{if $group|not} is-current{/if}" href={$rs_base|ezurl}{if $group|not} aria-current="true"{/if}><span>{'All (%count)'|i18n( 'extension/exp_request_shield',, hash( '%count', $activity.lines ) )|wash}</span></a></li>
        {foreach $groups as $rs_g}
            <li><a class="a4-chip a4-rs-chip is-{$rs_g}{if eq( $rs_g, $group )} is-current{/if}" href={concat( $rs_base, '/(group)/', $rs_g )|ezurl}{if eq( $rs_g, $group )} aria-current="true"{/if}><span>{$rs_group_names[$rs_g]|wash} ({$activity.groups[$rs_g]})</span></a></li>
        {/foreach}
        </ul>
    </div>
</div>

{include uri='design:requestshield/parts/log_table.tpl' rows=$rows group_names=$rs_group_names}

{if $total|gt( 0 )}
<div class="a4-pager a4-rs-pager">
    <span class="a4-muted">{'%first to %last of %total'|i18n( 'extension/exp_request_shield',, hash( '%first', $first, '%last', $last, '%total', $total ) )|wash}</span>
    {if $prev_url}<a class="a4-btn a4-btn-small" href={concat( '/', $prev_url )|ezurl} rel="prev">{'Newer'|i18n( 'extension/exp_request_shield' )}</a>{/if}
    {if $next_url}<a class="a4-btn a4-btn-small" href={concat( '/', $next_url )|ezurl} rel="next">{'Older'|i18n( 'extension/exp_request_shield' )}</a>{/if}
</div>
{/if}

{include uri='design:requestshield/parts/page_close.tpl'}
{undef $rs_group_names $rs_base}
