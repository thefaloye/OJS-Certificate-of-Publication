{**
 * Certificate dashboard: one certificate.
 *}
{assign var=k value="plugins.generic.bijphgrPublicationCertificate.dash."}
{include file=$dashHead}
{include file=$dashHeader}

<main class="bj-wrap">
	<p><a href="{$indexUrl|escape}">&larr; {translate key="`$k`backToList"}</a></p>

	{if $flash}
		<div class="bj-flash is-{$flash.type|escape}" role="status">{$flash.text|escape}</div>
	{/if}

	<section class="bj-card">
		<div class="bj-sub bj-num">{$row.number|escape}{if $row.version > 1} · {translate key="`$k`versionShort" v=$row.version}{/if}</div>
		<h2 style="font-size:1.35rem;margin:.2rem 0 .3rem">{if $row.title}{$row.title|escape}{else}{translate key="`$k`articleGone"}{/if}</h2>
		{if $row.authors}<div class="bj-sub" style="font-size:.95rem">{$row.authors|escape}</div>{/if}
		<p style="margin:.7rem 0 0">
			{if $row.held}<span class="pill warn">{translate key="`$k`status.held"}</span>
			{else}<span class="pill {if $row.status == 'valid'}ok{elseif $row.status == 'withdrawn'}off{else}warn{/if}">{translate key="`$k`status.`$row.status`"}</span>{/if}
			{if $row.status != 'withdrawn'}<span class="pill {if $row.emailStatus == 'sent'}ok{elseif $row.emailStatus == 'failed'}bad{else}warn{/if}">{translate key="`$k`email.`$row.emailStatus`"}</span>{/if}
		</p>
		<div class="bj-next {if $row.next == 'ok' || $row.next == 'sending' || $row.next == 'waiting' || $row.next == 'revisionComing'}is-ok{elseif $row.next == 'emailFailed' || $row.next == 'noRecipient' || $row.next == 'articleGone'}is-bad{else}is-warn{/if}">
			<strong>{translate key="`$k`next.label"}</strong> {translate key="`$k`next.`$row.next`"}
			{if $row.lastError && ($row.emailStatus == 'failed' || $row.held)}<div class="bj-err bj-mono" style="margin-top:.35rem">{$row.lastError|escape}</div>{/if}
		</div>
	</section>

	<div class="bj-cols">
		<div>
			<section class="bj-card">
				<h2>{translate key="`$k`section.article"}</h2>
				<dl class="bj-dl">
					<dt>{translate key="`$k`f.submission"}</dt><dd>{$row.submissionId|escape}</dd>
					{if $row.issueLabel}<dt>{translate key="`$k`f.issue"}</dt><dd>{$row.issueLabel|escape}</dd>{/if}
					<dt>{translate key="`$k`f.published"}</dt><dd>{$row.publicationDate|escape}</dd>
					{if $row.doi}<dt>DOI</dt><dd><a href="{$row.doiUrl|escape}">{$row.doiUrl|escape}</a></dd>{/if}
					<dt>{translate key="`$k`f.recipient"}</dt>
					<dd>{if $row.recipientName}{$row.recipientName|escape}{/if}{if $row.hasRecipient}<div class="bj-sub">{$row.recipientEmail|escape}</div>{else}<span class="bj-err">{translate key="`$k`noRecipientShort"}</span>{/if}</dd>
				</dl>
			</section>

			<section class="bj-card">
				<h2>{translate key="`$k`section.certificate"}</h2>
				<dl class="bj-dl">
					<dt>{translate key="`$k`f.number"}</dt><dd class="bj-num">{$row.number|escape}</dd>
					<dt>{translate key="`$k`f.version"}</dt><dd>{$row.version|escape}</dd>
					<dt>{translate key="`$k`f.generated"}</dt><dd>{if $row.generatedAt}{$row.generatedAt|escape}{else}&ndash;{/if}</dd>
					<dt>{translate key="`$k`f.emailSent"}</dt><dd>{if $row.sentAt}{$row.sentAt|escape}{else}&ndash;{/if}{if $row.attempts > 0} <span class="bj-sub">({translate key="`$k`attempts" n=$row.attempts})</span>{/if}</dd>
					<dt>{translate key="`$k`f.linkExpiry"}</dt><dd>{if $row.linkExpiry}{$row.linkExpiry|escape}{if $row.linkExpired} <span class="pill bad">{translate key="`$k`expired"}</span>{/if}{else}&ndash;{/if}</dd>
					<dt>{translate key="`$k`f.verifyUrl"}</dt><dd><code class="bj-copy">{$verifyUrl|escape}</code></dd>
				</dl>
			</section>

			<section class="bj-card">
				<h2>{translate key="`$k`section.versions"}</h2>
				{if $revisions|@count == 0}
					<div class="bj-sub">{translate key="`$k`noVersions"}</div>
				{else}
				<div class="bj-tablewrap"><table class="bj">
					<thead><tr><th>{translate key="`$k`f.version"}</th><th>{translate key="`$k`f.generated"}</th><th>{translate key="`$k`f.reason"}</th><th>{translate key="`$k`f.by"}</th><th></th></tr></thead>
					<tbody>
					{foreach from=$revisions item=rev}
						<tr>
							<td>{$rev.version|escape}{if $rev.isCurrent} <span class="pill info">{translate key="`$k`current"}</span>{/if}</td>
							<td>{$rev.date|escape}</td>
							<td>{$rev.reason|escape}</td>
							<td>{$rev.by|escape}</td>
							<td><a class="btn small secondary" href="{$rev.pdfUrl|escape}">PDF</a></td>
						</tr>
					{/foreach}
					</tbody>
				</table></div>
				{/if}
			</section>

			<section class="bj-card">
				<h2>{translate key="`$k`section.log"}</h2>
				<div class="bj-tablewrap"><table class="bj">
					<thead><tr><th>{translate key="`$k`f.when"}</th><th>{translate key="`$k`f.event"}</th><th>{translate key="`$k`f.by"}</th></tr></thead>
					<tbody>
					{foreach from=$log item=entry}
						<tr>
							<td style="white-space:nowrap">{$entry.date|escape}</td>
							<td>{$entry.event|escape}{if $entry.detail}<div class="bj-sub">{$entry.detail|escape}</div>{/if}</td>
							<td>{$entry.by|escape}</td>
						</tr>
					{/foreach}
					</tbody>
				</table></div>
			</section>
		</div>

		<aside>
			<section class="bj-card">
				<h2>{translate key="`$k`section.actions"}</h2>

				{if $canManage}
					<div class="bj-act">
						<form method="post" action="{$resendUrl|escape}" data-confirm="{translate key="`$k`confirm.resend" email=$row.recipientEmail}" onsubmit="return confirm(this.getAttribute('data-confirm'));">
							{csrf}
							<button class="btn" type="submit"{if !$row.canResend} disabled{/if}>{translate key="`$k`action.resend"}</button>
						</form>
						<p>{translate key="`$k`action.resendHelp"}</p>
					</div>
					<div class="bj-act">
						<form method="post" action="{$regenerateUrl|escape}" data-confirm="{translate key="`$k`confirm.regenerate"}" onsubmit="return confirm(this.getAttribute('data-confirm'));">
							{csrf}
							<button class="btn secondary" type="submit"{if !$row.canRegenerate} disabled{/if}>{translate key="`$k`action.regenerate"}</button>
						</form>
						<p>{translate key="`$k`action.regenerateHelp"}</p>
					</div>
				{else}
					<div class="bj-act"><p>{translate key="`$k`managerOnly"}</p></div>
				{/if}

				<div class="bj-act bj-links">
					{if !$row.held}<a href="{$pdfUrl|escape}">{translate key="`$k`link.pdf"}</a>{/if}
					<a href="{$verifyUrl|escape}" target="_blank" rel="noopener">{translate key="`$k`link.verify"}</a>
					{if $row.articleUrl && $row.published}<a href="{$row.articleUrl|escape}" target="_blank" rel="noopener">{translate key="`$k`link.article"}</a>{/if}
					{if $row.articleAvailable}<a href="{$row.workflowUrl|escape}">{translate key="`$k`link.workflow"}</a>{/if}
				</div>
			</section>
		</aside>
	</div>
</main>
</body>
</html>
