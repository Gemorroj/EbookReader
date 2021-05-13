<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Exception\ParserException;
use EbookReader\Meta\MobiMeta;

/**
 * @see https://wiki.mobileread.com/wiki/MOBI
 * @see https://github.com/choccybiccy/mobi
 */
class MobiDriver extends AbstractDriver
{
    public function isValid(): bool
    {
        $content = \file_get_contents($this->getFile(), false, null, 60, 8);

        return 'BOOKMOBI' === $content;
    }

    public function getMeta(): MobiMeta
    {
        try {
            $f = new \SplFileObject($this->getFile(), 'r');
        } catch (\Exception $e) {
            throw new ParserException();
        }

        $f->fseek(60);
        if ('BOOKMOBI' !== $f->fread(8)) {
            unset($f); // close file
            throw new ParserException();
        }

        try {
            $this->seekPalmDb($f);
            $this->seekPalmDoc($f);
            $title = $this->seekMobiHeader($f);

            //$this->parseExth(); // todo
        } catch (\Throwable $e) {
            unset($f); // close file
            throw $e;
        }

        return new MobiMeta($title);
    }

    /**
     * @see https://wiki.mobileread.com/wiki/PDB#Palm_Database_Format
     */
    protected function seekPalmDb(\SplFileObject $f): void
    {
        $f->fseek(8, \SEEK_CUR);
        $content = $f->fread(2);
        if (false === $content) {
            throw new ParserException();
        }
        $records = (int) \hexdec(\bin2hex($content));

        $f->fseek((4 + 1 + 3) * $records, \SEEK_CUR);
    }

    /**
     * @see https://wiki.mobileread.com/wiki/MOBI#PalmDOC_Header
     */
    protected function seekPalmDoc(\SplFileObject $f): void
    {
        $f->fseek(16, \SEEK_CUR);
    }

    /**
     * @see https://wiki.mobileread.com/wiki/MOBI#MOBI_Header
     */
    protected function seekMobiHeader(\SplFileObject $f): string
    {
        $mobiHeaderStart = $f->ftell() + 2;

        $f->fseek(70, \SEEK_CUR);

        $data = $f->fread(8);
        if (false === $data) {
            throw new ParserException();
        }
        $titleData = \unpack('N*', $data);
        if (false === $titleData) {
            throw new ParserException();
        }

        $f->fseek($mobiHeaderStart + ($titleData[1] - 16));

        $title = $f->fread($titleData[2]);
        if (false === $title) {
            throw new ParserException();
        }

        $f->fseek($mobiHeaderStart + 24);

        return $title;
    }
}
