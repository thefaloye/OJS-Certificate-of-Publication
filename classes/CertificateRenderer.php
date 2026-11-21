<?php

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders the Certificate of Publication as an A4-landscape PDF with mPDF
 * (bundled with OJS 3.5, so no extra library is installed).
 *
 * Colours are taken from the BIJPHGR logo.
 */
class CertificateRenderer
{
    // BIJPHGR brand palette (sampled from the logo).
    public const NAVY = '#002A59';      // logo plate / deep navy
    public const BLUE = '#033187';      // journal-name blue
    public const GREEN = '#4EB902';     // growth-bars green
    public const MAGENTA = '#80076C';   // tagline
    public const GREY = '#5A6472';
    public const TAGLINE = 'Advancing Knowledge. Inspiring Progress.';

    public function __construct(
        private CertificateStorage $storage,
        private ?string $assetDir = null
    ) {
        $this->assetDir ??= dirname(__DIR__) . '/resources';
    }

    public function render(array $d): string
    {
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'tempDir' => $this->storage->tempDir(),
            'default_font' => 'dejavusans',
            'autoPageBreak' => false,
        ]);

        $mpdf->SetTitle('Certificate of Publication ' . $d['certificateNumber']);
        $mpdf->SetAuthor($d['journalName']);
        $mpdf->SetCreator('BIJPHGR Publication Certificate plugin');
        $mpdf->SetSubject('Certificate of Publication: ' . $d['articleTitle']);
        $mpdf->SetKeywords($d['certificateNumber']);

        $mpdf->WriteHTML($this->html($d));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function dataUri(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };
        return $mime ? 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path)) : null;
    }

    private function html(array $d): string
    {
        $navy = self::NAVY;
        $blue = self::BLUE;
        $green = self::GREEN;
        $magenta = self::MAGENTA;
        $grey = self::GREY;

        // Scale text so long titles / many authors still fit on one page.
        $titleLen = mb_strlen($d['articleTitle']);
        $titlePt = $titleLen > 220 ? 13 : ($titleLen > 150 ? 15 : ($titleLen > 90 ? 17 : 19));
        $authorCount = count($d['authors']);
        $authorsLen = mb_strlen($d['allAuthors']);
        $authorsPt = ($authorCount > 12 || $authorsLen > 260) ? 9.5 : (($authorCount > 7 || $authorsLen > 150) ? 11.5 : 14);

        $qr = QrCodeImage::svgDataUri($d['verifyUrl']);

        $logo = $this->dataUri($this->assetDir . '/logo-icon.png');
        $logoHtml = $logo ? '<img src="' . $logo . '" style="height:22mm" />' : '';

        $signature = null;
        if ($d['signatureImage'] !== '' && ($abs = $this->storage->resolve($d['signatureImage'])) !== null) {
            $signature = $this->dataUri($abs);
        }
        $signatureHtml = $signature ? '<img src="' . $signature . '" style="height:14mm" />' : '';

        $issue = $d['issueLabel'];
        $publishedLine = '<b>' . $this->e($d['journalName']) . '</b>';
        if ($issue !== '') {
            $publishedLine .= '<br />' . $this->e($issue);
        }
        $publishedLine .= '<br />on <b>' . $this->e($d['publicationDate']) . '</b>';

        $details = ['Article ID: <b>' . $this->e($d['articleId']) . '</b>'];
        if ($d['doi'] !== '') {
            $details[] = 'DOI: <b>' . $this->e($d['doiUrl']) . '</b>';
        }
        $detailsHtml = implode(' &nbsp;&nbsp;|&nbsp;&nbsp; ', $details)
            . '<br />Article: ' . $this->e($d['articleUrl']);

        $revision = $d['certificateVersion'] > 1
            ? '<br />Revision ' . (int) $d['certificateVersion']
            : '';

        $publisher = $d['publisherName'] !== '' ? 'Published by ' . $this->e($d['publisherName']) : '';
        $signatoryName = $this->e($d['signatoryName']);
        $signatoryTitle = $this->e($d['signatoryTitle']);

        $html = <<<HTML
<html><head><style>
  body { font-family: dejavusans; color: {$navy}; }
  .serif { font-family: dejavuserif; }
  .c { text-align: center; }
</style></head><body>

<div style="left:8mm; top:8mm; width:281mm; height:194mm; border:1.2mm solid {$navy};"></div>
<div style="left:11.5mm; top:11.5mm; width:274mm; height:187mm; border:0.3mm solid {$green};"></div>

<div style="left:20mm; top:16mm; width:28mm;">{$logoHtml}</div>
<div style="left:46mm; top:17mm; width:165mm; font-size:14.5pt; font-weight:bold; color:{$blue}; line-height:1.15;">{$this->e($d['journalName'])}</div>
<div style="left:46mm; top:34mm; width:165mm; font-size:9pt; font-style:italic; color:{$magenta};">{$this->e(self::TAGLINE)}</div>
<div style="left:205mm; top:19mm; width:73mm; text-align:right; font-size:7.5pt; color:{$grey};">CERTIFICATE NUMBER<br /><span style="font-size:11pt; font-weight:bold; color:{$navy};">{$this->e($d['certificateNumber'])}</span>{$revision}</div>
<div style="left:20mm; top:41.5mm; width:257mm; height:0.7mm; background-color:{$green};"></div>

<div class="c serif" style="left:20mm; top:49mm; width:257mm; font-size:25pt; letter-spacing:3pt; color:{$navy};">CERTIFICATE OF PUBLICATION</div>
<div class="c" style="left:20mm; top:63mm; width:257mm; font-size:10pt; color:{$grey};">This is to certify that the article titled</div>

<div class="c serif" style="left:32mm; top:69.5mm; width:233mm; height:22mm; font-size:{$titlePt}pt; font-weight:bold; line-height:1.25; color:{$navy};">{$this->e($d['articleTitle'])}</div>

<div class="c" style="left:20mm; top:93mm; width:257mm; font-size:10pt; color:{$grey};">written by</div>
<div class="c serif" style="left:32mm; top:99mm; width:233mm; height:16mm; font-size:{$authorsPt}pt; line-height:1.3; color:{$blue};">{$this->e($d['allAuthors'])}</div>

<div class="c" style="left:20mm; top:116mm; width:257mm; font-size:10pt; color:{$grey};">has been published in</div>
<div class="c" style="left:20mm; top:122mm; width:257mm; font-size:11.5pt; line-height:1.35; color:{$navy};">{$publishedLine}</div>

<div class="c" style="left:20mm; top:141mm; width:257mm; font-size:7.5pt; line-height:1.5; color:{$grey};">{$detailsHtml}</div>

<div style="left:24mm; top:158mm; width:78mm; height:14mm;">{$signatureHtml}</div>
<div style="left:24mm; top:172.5mm; width:78mm; height:0.3mm; background-color:{$navy};"></div>
<div style="left:24mm; top:174mm; width:78mm; font-size:10pt; font-weight:bold; color:{$navy};">{$signatoryName}</div>
<div style="left:24mm; top:179.5mm; width:78mm; font-size:8.5pt; color:{$grey};">{$signatoryTitle}</div>

<div style="left:134mm; top:153mm; width:29mm; height:29mm;"><img src="{$qr}" style="width:29mm; height:29mm;" /></div>
<div class="c" style="left:119mm; top:182.5mm; width:60mm; font-size:6.5pt; color:{$grey};">Scan to verify this certificate</div>

<div style="left:190mm; top:160mm; width:87mm; text-align:right; font-size:8pt; line-height:1.5; color:{$grey};">{$publisher}<br />Verify online:<br /><span style="font-size:6.5pt; color:{$navy};">{$this->e($d['verifyUrl'])}</span></div>

<div class="c" style="left:20mm; top:190mm; width:257mm; font-size:6pt; color:{$grey};">The verification page shows the current public status of this publication. Certificate details reflect the published record at the time of issue.</div>

</body></html>
HTML;

        // mPDF 8.2 loops forever on a stylesheet rule "div {position:absolute}", so the
        // positioning is injected inline on every box instead.
        return preg_replace('/<div\b([^>]*?)\bstyle="/', '<div$1style="position:absolute; ', $html);
    }
}
