<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Pdf;

/**
 * FPDF (setasign/fpdf, MIT) plus what PDF/A-3b needs: PDF 1.7 header with binary marker, embedded TrueType fonts only,
 * XMP metadata (pdfaid 3/B + Factur-X extension schema) matching the Info dictionary, sRGB output intent, an embedded
 * file with /AFRelationship referenced from the catalog /AF array, and a trailer /ID.
 *
 * @internal used by HybridPdf
 */
class PdfA3 extends \FPDF
{
    public const PRODUCER = 'W System e-invoice SDK for PHP';

    /** set when enc() had to simplify or replace a character since the last reset */
    public static bool $replaced = false;

    private ?string $attName = null;
    private string $attData = '';
    private string $attDesc = '';
    private string $attRel = 'Alternative';
    private string $fxLevel = '';
    private string $fxDocType = 'INVOICE';
    private int $time;
    private string $lang = 'de-DE';
    private array $objs = [];
    /** @var ?callable(self): void */
    public $footer = null;

    public function __construct(?int $time = null)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->time = $time ?? time();
        $this->SetCompression(true);
        $this->AddFont('WS', '', 'Vera.json', self::fontDir());
        $this->AddFont('WS', 'B', 'VeraBd.json', self::fontDir());
        $this->SetCreator(self::PRODUCER, true);
        $this->metadata['Producer'] = self::PRODUCER;
    }

    public function Footer()
    {
        if ($this->footer) {
            ($this->footer)($this);
        }
    }

    public static function fontDir(): string
    {
        return dirname(__DIR__, 2) . '/resources/fonts';
    }

    public function attach(string $name, string $data, string $description, string $level, string $relationship = 'Alternative'): void
    {
        $this->attName = $name;
        $this->attData = $data;
        $this->attDesc = $description;
        $this->fxLevel = $level;
        $this->attRel = $relationship;
    }

    public function setLang(string $lang): void
    {
        $this->lang = $lang;
    }

    /** UTF-8 -> the fonts' Windows-1252 encoding. Characters outside it are simplified (Ł -> L) or replaced by '?'. */
    public static function enc(?string $s): string
    {
        if ($s === null || $s === '') {
            return '';
        }
        $s = strtr($s, ["\u{2212}" => '-', "\u{2010}" => '-', "\u{2011}" => '-', "\u{00A0}" => ' ', "\u{202F}" => ' ', "\t" => ' ']);
        $out = '';
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $b = self::to1252($ch);
            if ($b === null) {
                self::$replaced = true;
                $map = ['ł' => 'l', 'Ł' => 'L', 'đ' => 'd', 'Đ' => 'D', 'ı' => 'i', 'ħ' => 'h', 'Ħ' => 'H', 'ŧ' => 't', '€' => "\x80"];
                if (isset($map[$ch])) {
                    $b = $map[$ch];
                } elseif (class_exists(\Normalizer::class)) {
                    $d = preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($ch, \Normalizer::FORM_D));
                    $b = ($d !== '' && $d !== $ch) ? (self::to1252($d) ?? '?') : '?';
                } else {
                    $b = '?';
                }
            }
            $out .= $b;
        }
        return $out;
    }

    private static function to1252(string $ch): ?string
    {
        if (strlen($ch) === 1) {
            $o = ord($ch);
            return ($o >= 32 && $o < 127) || $ch === "\n" ? $ch : ($o < 32 ? ' ' : null);
        }
        $b = @mb_convert_encoding($ch, 'Windows-1252', 'UTF-8');
        if ($b === false || $b === '?' || strlen($b) !== 1) {
            return null;
        }
        $o = ord($b);
        if (in_array($o, [0x7f, 0x81, 0x8d, 0x8f, 0x90, 0x9d], true)) {
            return null;
        }
        return mb_convert_encoding($b, 'UTF-8', 'Windows-1252') === $ch ? $b : null;
    }

    private function pdfDate(): string
    {
        return gmdate('\D:YmdHis', $this->time) . "+00'00'";
    }

    protected function _putheader()
    {
        $this->_put('%PDF-1.7');
        $this->_put("%\xE2\xE3\xCF\xD3");
    }

    protected function _putinfo()
    {
        $this->metadata['CreationDate'] = $this->pdfDate();
        $this->metadata['ModDate'] = $this->pdfDate();
        foreach ($this->metadata as $key => $value) {
            $this->_put('/' . $key . ' ' . $this->_textstring($value));
        }
    }

    private function streamObj(string $dict, string $data, bool $compress = true): int
    {
        if ($compress) {
            $data = gzcompress($data);
            $dict .= ' /Filter /FlateDecode';
        }
        $this->_newobj();
        $this->_put("<<$dict /Length " . strlen($data) . '>>');
        $this->_putstream($data);
        $this->_put('endobj');
        return $this->n;
    }

    protected function _putresources()
    {
        parent::_putresources();
        $icc = file_get_contents(dirname(__DIR__, 2) . '/resources/sRGB2014.icc');
        $iccN = $this->streamObj('/N 3', $icc);
        $this->_newobj();
        $this->_put("<</Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB IEC61966-2.1) /RegistryName (http://www.color.org) /Info (sRGB IEC61966-2.1) /DestOutputProfile $iccN 0 R>>");
        $this->_put('endobj');
        $this->objs['oi'] = $this->n;
        if ($this->attName !== null) {
            $d = $this->pdfDate();
            $ef = $this->streamObj('/Type /EmbeddedFile /Subtype /text#2Fxml /Params <</ModDate ' . $this->_textstring($d) . ' /Size ' . strlen($this->attData)
                . ' /CheckSum <' . md5($this->attData) . '>>>', $this->attData);
            $this->_newobj();
            $fn = $this->_textstring($this->attName);
            $this->_put("<</Type /Filespec /F $fn /UF $fn /Desc " . $this->_textstring($this->attDesc) . " /AFRelationship /{$this->attRel} /EF <</F $ef 0 R /UF $ef 0 R>>>>");
            $this->_put('endobj');
            $this->objs['fs'] = $this->n;
        }
        $this->objs['xmp'] = $this->streamObj('/Type /Metadata /Subtype /XML', $this->xmp(), false);
    }

    protected function _putcatalog()
    {
        parent::_putcatalog();
        $this->_put('/Metadata ' . $this->objs['xmp'] . ' 0 R');
        $this->_put('/OutputIntents [' . $this->objs['oi'] . ' 0 R]');
        $this->_put('/Lang ' . $this->_textstring($this->lang));
        $this->_put('/MarkInfo <</Marked false>>');
        if (isset($this->objs['fs'])) {
            $this->_put('/Names <</EmbeddedFiles <</Names [' . $this->_textstring($this->attName) . ' ' . $this->objs['fs'] . ' 0 R]>>>>');
            $this->_put('/AF [' . $this->objs['fs'] . ' 0 R]');
            $this->_put('/PageMode /UseAttachments');
        }
    }

    protected function _puttrailer()
    {
        parent::_puttrailer();
        $id = md5($this->buffer);
        $this->_put("/ID [<$id> <$id>]");
    }

    private static function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xmp(): string
    {
        $iso = gmdate('Y-m-d\TH:i:s', $this->time) . '+00:00';
        $t = self::x($this->metadata['Title'] ?? '');
        $a = self::x($this->metadata['Author'] ?? '');
        $s = self::x($this->metadata['Subject'] ?? '');
        $p = self::x(self::PRODUCER);
        $fx = '';
        if ($this->attName !== null) {
            $fx = '<rdf:Description rdf:about="" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/" xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#">
<pdfaExtension:schemas><rdf:Bag><rdf:li rdf:parseType="Resource">
<pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema>
<pdfaSchema:namespaceURI>urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#</pdfaSchema:namespaceURI>
<pdfaSchema:prefix>fx</pdfaSchema:prefix>
<pdfaSchema:property><rdf:Seq>
<rdf:li rdf:parseType="Resource"><pdfaProperty:name>DocumentFileName</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>The name of the embedded XML document</pdfaProperty:description></rdf:li>
<rdf:li rdf:parseType="Resource"><pdfaProperty:name>DocumentType</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>The type of the hybrid document in capital letters, e.g. INVOICE or ORDER</pdfaProperty:description></rdf:li>
<rdf:li rdf:parseType="Resource"><pdfaProperty:name>Version</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>The actual version of the standard applying to the embedded XML document</pdfaProperty:description></rdf:li>
<rdf:li rdf:parseType="Resource"><pdfaProperty:name>ConformanceLevel</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>The conformance level of the embedded XML document</pdfaProperty:description></rdf:li>
</rdf:Seq></pdfaSchema:property>
</rdf:li></rdf:Bag></pdfaExtension:schemas>
</rdf:Description>
<rdf:Description rdf:about="" xmlns:fx="urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#"><fx:DocumentType>' . $this->fxDocType . '</fx:DocumentType><fx:DocumentFileName>'
                . self::x($this->attName) . '</fx:DocumentFileName><fx:Version>1.0</fx:Version><fx:ConformanceLevel>' . self::x($this->fxLevel) . '</fx:ConformanceLevel></rdf:Description>
';
        }
        return "<?xpacket begin=\"\u{FEFF}\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>
<x:xmpmeta xmlns:x=\"adobe:ns:meta/\">
<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">
<rdf:Description rdf:about=\"\" xmlns:pdfaid=\"http://www.aiim.org/pdfa/ns/id/\"><pdfaid:part>3</pdfaid:part><pdfaid:conformance>B</pdfaid:conformance></rdf:Description>
<rdf:Description rdf:about=\"\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\">
<dc:title><rdf:Alt><rdf:li xml:lang=\"x-default\">$t</rdf:li></rdf:Alt></dc:title>
<dc:creator><rdf:Seq><rdf:li>$a</rdf:li></rdf:Seq></dc:creator>
<dc:description><rdf:Alt><rdf:li xml:lang=\"x-default\">$s</rdf:li></rdf:Alt></dc:description>
</rdf:Description>
<rdf:Description rdf:about=\"\" xmlns:pdf=\"http://ns.adobe.com/pdf/1.3/\"><pdf:Producer>$p</pdf:Producer></rdf:Description>
<rdf:Description rdf:about=\"\" xmlns:xmp=\"http://ns.adobe.com/xap/1.0/\"><xmp:CreatorTool>$p</xmp:CreatorTool><xmp:CreateDate>$iso</xmp:CreateDate><xmp:ModifyDate>$iso</xmp:ModifyDate><xmp:MetadataDate>$iso</xmp:MetadataDate></rdf:Description>
$fx</rdf:RDF>
</x:xmpmeta>
<?xpacket end=\"w\"?>";
    }
}
