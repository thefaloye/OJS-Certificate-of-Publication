{**
 * Plugin settings form
 *}
<script>
	$(function() {ldelim}
		$('#bijphgrCertificateSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
		$('#bijphgrSignatureFile').on('change', function() {ldelim}
			var file = this.files && this.files[0];
			if (!file) {ldelim} $('#bijphgrSignatureData').val(''); return; {rdelim}
			var reader = new FileReader();
			reader.onload = function(e) {ldelim} $('#bijphgrSignatureData').val(e.target.result); {rdelim};
			reader.readAsDataURL(file);
		{rdelim});
	{rdelim});
</script>

<form class="pkp_form" id="bijphgrCertificateSettings" method="POST" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}

	{if $missingSettings}
		<div class="pkp_notification">
			<strong>{translate key="plugins.generic.bijphgrPublicationCertificate.settings.incomplete"}</strong>
			{translate key="plugins.generic.bijphgrPublicationCertificate.settings.incompleteHelp"}
		</div>
	{/if}

	{fbvFormArea id="bijphgrCertificateIssuer" title="plugins.generic.bijphgrPublicationCertificate.settings.issuer"}
		{fbvFormSection description="plugins.generic.bijphgrPublicationCertificate.settings.publisherNameHelp"}
			{fbvElement type="text" id="publisherName" value=$publisherName label="plugins.generic.bijphgrPublicationCertificate.settings.publisherName" size=$fbvStyles.size.LARGE}
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="signatoryName" value=$signatoryName label="plugins.generic.bijphgrPublicationCertificate.settings.signatoryName" size=$fbvStyles.size.LARGE}
			{fbvElement type="text" id="signatoryTitle" value=$signatoryTitle label="plugins.generic.bijphgrPublicationCertificate.settings.signatoryTitle" size=$fbvStyles.size.LARGE}
		{/fbvFormSection}
		{fbvFormSection label="plugins.generic.bijphgrPublicationCertificate.settings.signature" description="plugins.generic.bijphgrPublicationCertificate.settings.signatureHelp"}
			<input type="file" id="bijphgrSignatureFile" accept="image/png,image/jpeg" />
			<input type="hidden" id="bijphgrSignatureData" name="signatureData" value="" />
			{if $hasSignature}
				{fbvElement type="checkbox" id="removeSignature" value="1" label="plugins.generic.bijphgrPublicationCertificate.settings.removeSignature" checked=false}
			{/if}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="bijphgrCertificateNumbering" title="plugins.generic.bijphgrPublicationCertificate.settings.numbering"}
		{fbvFormSection}
			{fbvElement type="text" id="certificatePrefix" value=$certificatePrefix label="plugins.generic.bijphgrPublicationCertificate.settings.prefix" size=$fbvStyles.size.SMALL}
			{fbvElement type="text" id="downloadLinkDays" value=$downloadLinkDays label="plugins.generic.bijphgrPublicationCertificate.settings.downloadDays" size=$fbvStyles.size.SMALL}
		{/fbvFormSection}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="autoRevise" value="1" checked=$autoRevise label="plugins.generic.bijphgrPublicationCertificate.settings.autoRevise"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="bijphgrCertificateEmail" title="plugins.generic.bijphgrPublicationCertificate.settings.email"}
		{fbvFormSection description="plugins.generic.bijphgrPublicationCertificate.settings.emailHelp"}
			{fbvElement type="text" id="emailSubject" value=$emailSubject label="plugins.generic.bijphgrPublicationCertificate.settings.emailSubject" size=$fbvStyles.size.LARGE}
			{fbvElement type="textarea" id="emailBody" value=$emailBody label="plugins.generic.bijphgrPublicationCertificate.settings.emailBody" rich=false}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>
