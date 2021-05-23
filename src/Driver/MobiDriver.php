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

    public function getCover(): ?string
    {
        // todo
        throw new \RuntimeException('Not implemented');
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
            $data = $this->parseExth($f);
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            unset($f); // close file
        }

        return new MobiMeta(
            $title,
            $data['author']
        );
    }

    /**
     * @return array{author: string|null}
     */
    protected function parseExth(\SplFileObject $f): array
    {
        $f->fseek(4, \SEEK_CUR); // length
        $rawRecords = $f->fread(4);
        if (false === $rawRecords) {
            throw new ParserException();
        }
        $records = (int) \hexdec(\bin2hex($rawRecords));

        $meta = [
            'author' => null,
        ];
        for ($i = 0; $i < $records; ++$i) {
            $rawType = $f->fread(4);
            if (false === $rawType) {
                throw new ParserException();
            }
            $rawLength = $f->fread(4);
            if (false === $rawLength) {
                throw new ParserException();
            }

            $type = (int) \hexdec(\bin2hex($rawType));
            $length = (int) \hexdec(\bin2hex($rawLength));

            if ($length > 0) {
                $data = $f->fread($length - 8);
                if (false === $data) {
                    throw new ParserException();
                }
            } else {
                $data = null;
            }

            // https://wiki.mobileread.com/wiki/MOBI#EXTH_Header
            if (100 === $type) {
                $meta['author'] = $data;
            }
        }

        return $meta;
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
        $f->fseek(2, \SEEK_CUR);
        $mobiHeaderStart = $f->ftell();
        if (false === $mobiHeaderStart) {
            throw new ParserException();
        }
        if ('MOBI' !== $f->fread(4)) {
            throw new ParserException();
        }
        $length = (int) \hexdec(\bin2hex($f->fread(4)));

        $f->fseek(60, \SEEK_CUR);

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

        $f->fseek($mobiHeaderStart + $length + 4);

        return $title;
    }
}
