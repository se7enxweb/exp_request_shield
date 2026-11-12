{* Setup > Request shield > Rules (requestshield/rules): which main rule file the shield loads and why, whether it
   compiles, and the text of the main file and the files it includes. Read-only; the files are edited on the server
   and checked with bin/request-shield (doc/configuration.md).

   Variables: status, files (expRequestShield::ruleFiles(): name, path, main, size, lines, text), show_text
   (requestshield.ini [AdminSettings] ShowRuleText), extension_dir, view. *}
{include uri='design:requestshield/parts/page_open.tpl' view=$view title='Rules'|i18n( 'extension/exp_request_shield' )
         intro='The shield reads one main rule file, which may include others. They are plain text files on the server; this page only shows them.'|i18n( 'extension/exp_request_shield' )}

{if $status.error}
<div class="a4-notice is-bad" role="alert">
    <h2 class="a4-h3 a4-notice-title">{'The rules do not compile'|i18n( 'extension/exp_request_shield' )}</h2>
    <pre class="a4-pre">{$status.error|wash}</pre>
</div>
{else}
<div class="a4-notice is-ok" role="status"><p>{'The rules compile. Mode: %mode.'|i18n( 'extension/exp_request_shield',, hash( '%mode', $status.mode ) )|wash}</p></div>
{/if}

{if eq( $status.rule_source, 'extension' )}
<div class="a4-notice is-info">
    <p>{'The shield uses the extension\'s default rule file. To change the rules, copy it to the site\'s settings and edit the copy; updates of the extension replace its own files.'|i18n( 'extension/exp_request_shield' )}</p>
    <p><code class="a4-code">{$status.site_rule_file|wash}</code></p>
</div>
{/if}

<section class="a4-section" aria-labelledby="rs-check-title">
<h2 class="a4-h2" id="rs-check-title">{'Check the rules on the server'|i18n( 'extension/exp_request_shield' )}</h2>
<p>{'After every change, as the user the web server runs PHP as:'|i18n( 'extension/exp_request_shield' )}</p>
<pre class="a4-pre">php {$extension_dir|wash}/bin/request-shield check {$status.rule_file|wash}
php {$extension_dir|wash}/bin/request-shield test {$status.rule_file|wash}
php {$extension_dir|wash}/bin/request-shield trace {$status.rule_file|wash} "GET https://www.example.org/"</pre>
</section>

<section class="a4-section" aria-labelledby="rs-files-title">
<h2 class="a4-h2" id="rs-files-title">{'Files'|i18n( 'extension/exp_request_shield' )}</h2>
{if $files|count}
<div class="a4-table-wrap">
<table class="a4-table">
<thead><tr><th scope="col">{'File'|i18n( 'extension/exp_request_shield' )}</th><th scope="col">{'Path'|i18n( 'extension/exp_request_shield' )}</th><th scope="col" class="a4-num">{'Lines'|i18n( 'extension/exp_request_shield' )}</th></tr></thead>
<tbody>
{foreach $files as $rs_i => $rs_file}
<tr><td>{if $show_text}<a href="#rs-file-{$rs_i}">{$rs_file.name|wash}</a>{else}{$rs_file.name|wash}{/if}{if $rs_file.main} <span class="a4-badge">{'main'|i18n( 'extension/exp_request_shield' )}</span>{/if}</td>
    <td><code class="a4-code a4-rs-path">{$rs_file.path|wash}</code></td><td class="a4-num">{$rs_file.lines}</td></tr>
{/foreach}
</tbody>
</table>
</div>
{if $show_text}
{foreach $files as $rs_i => $rs_file}
<details class="a4-fold a4-rs-file" id="rs-file-{$rs_i}"{if $rs_file.main} open="open"{/if}>
    <summary>{$rs_file.name|wash}</summary>
    <div class="a4-fold-body"><pre class="a4-pre a4-rs-source">{$rs_file.text|wash}</pre></div>
</details>
{/foreach}
{/if}
{else}
<p class="a4-empty">{'The main rule file cannot be read.'|i18n( 'extension/exp_request_shield' )}</p>
{/if}
</section>

{include uri='design:requestshield/parts/page_close.tpl'}
