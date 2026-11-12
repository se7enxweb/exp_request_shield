{* The table of log lines on the dashboard and the log page. Input: $rows (expRequestShield::activity() recent: time,
   client, action, group, status, reason, rule, method, url, agent), $group_names. The client is the address as the
   log wrote it: by default its network (IPv4 /24, IPv6 /48), never a person's address ("set log-ip masked"). *}
{if $rows|count}
<div class="a4-table-wrap">
<table class="a4-table a4-rs-log">
<thead><tr>
    <th scope="col">{'Time'|i18n( 'extension/exp_request_shield' )}</th>
    <th scope="col">{'Decision'|i18n( 'extension/exp_request_shield' )}</th>
    <th scope="col" class="a4-num">{'Status'|i18n( 'extension/exp_request_shield' )}</th>
    <th scope="col">{'Request'|i18n( 'extension/exp_request_shield' )}</th>
    <th scope="col">{'Rule and reason'|i18n( 'extension/exp_request_shield' )}</th>
    <th scope="col">{'Client'|i18n( 'extension/exp_request_shield' )}</th>
</tr></thead>
<tbody>
{foreach $rows as $rs_row}
<tr>
    <td class="a4-nowrap">{$rs_row.time|l10n( 'shortdatetime' )}</td>
    <td><span class="a4-badge a4-rs-badge is-{$rs_row.group|wash}" title="{first_set( $group_names[$rs_row.group], '' )|wash}">{$rs_row.action|wash}</span></td>
    <td class="a4-num">{$rs_row.status|wash}</td>
    <td class="a4-rs-request"><span class="a4-muted">{$rs_row.method|wash}</span> <span class="a4-rs-url" title="{$rs_row.agent|wash}">{$rs_row.url|wash}</span></td>
    <td>{if $rs_row.rule}<code class="a4-code">{$rs_row.rule|wash}</code> {/if}<span class="a4-muted">{$rs_row.reason|wash}</span></td>
    <td class="a4-nowrap"><code class="a4-code">{$rs_row.client|wash}</code></td>
</tr>
{/foreach}
</tbody>
</table>
</div>
{else}
<p class="a4-empty">{'No log lines in this period.'|i18n( 'extension/exp_request_shield' )}</p>
{/if}
