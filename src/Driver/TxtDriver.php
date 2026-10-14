<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Cover\Cover;
use EbookReader\Exception\ParserException;
use EbookReader\Format;
use EbookReader\Meta\TxtMeta;
use EbookReader\Section\TxtSection;
use EbookReader\TableOfContents\TableOfContents;

final class TxtDriver extends AbstractDriver
{
    private ?string $internalFile = null;
    /**
     * @var string[]
     */
    protected array $coverFilenames = [
        'cover',
        'img',
        'image',
        'cover_0',
        'img_0',
        'image_0',
    ];

    protected function makeInternalFile(): string
    {
        $zip = new \ZipArchive();
        $res = $zip->open($this->getFile(), \ZipArchive::RDONLY);
        if (true === $res) {
            $txtFile = null;
            // get first .txt file
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $fileName = $zip->getNameIndex($i);
                if (false === $fileName) {
                    continue;
                }
                if ('txt' === \pathinfo($fileName, \PATHINFO_EXTENSION)) {
                    $txtFile = $fileName;
                    break;
                }
            }
            $zip->close();
            if (null === $txtFile) {
                throw new ParserException();
            }

            $this->internalFile = 'zip://'.$this->getFile().'#'.$txtFile;
        } else {
            $this->internalFile = 'file://'.$this->getFile();
        }

        return $this->internalFile;
    }

    protected function isBinary(string $data): bool
    {
        return !\mb_check_encoding($data, 'UTF-8');
    }

    public function getFormat(): Format
    {
        return Format::TXT;
    }

    public function isValid(): bool
    {
        try {
            $file = $this->internalFile ?? $this->makeInternalFile();
            $f = new \SplFileObject($file, 'r');
            $head = $f->fread(4096);
            if (false === $head) {
                return false;
            }

            return !$this->isBinary($head);
        } catch (\Throwable $e) {
            return false;
        } finally {
            unset($f); // close file
        }
    }

    /**
     * @return TxtSection[]
     */
    public function getSections(): array
    {
        try {
            $file = $this->internalFile ?? $this->makeInternalFile();
            $f = new \SplFileObject($file, 'r');
        } catch (\Exception $e) {
            throw new ParserException(previous: $e);
        }

        try {
            $text = '';
            while (!$f->eof()) {
                $text .= $f->fread(4096);
            }
        } finally {
            unset($f); // close file
        }

        $text = \mb_trim($text);

        return [
            new TxtSection('0', $text, null),
        ];
    }

    public function getCover(): ?Cover
    {
        $cover = null;
        $zip = new \ZipArchive();
        $res = $zip->open($this->getFile(), \ZipArchive::RDONLY);
        if (true === $res) {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $fileName = $zip->getNameIndex($i);
                if (false === $fileName) {
                    continue;
                }

                if (\in_array(\pathinfo($fileName, \PATHINFO_FILENAME), $this->coverFilenames, true)) {
                    $fileContent = $zip->getFromIndex($i);
                    if (false === $fileContent) {
                        continue;
                    }
                    $mime = $this->getMimeDetector()->detect($fileContent);
                    if ($mime) {
                        $cover = new Cover($fileContent, $mime);
                        break;
                    }
                }
            }
            $zip->close();
        }

        return $cover;
    }

    /**
     * @return TableOfContents[]
     */
    public function getTableOfContents(): array
    {
        return [];
    }

    public function getMeta(): TxtMeta
    {
        try {
            $file = $this->internalFile ?? $this->makeInternalFile();
            $f = new \SplFileObject($file, 'r');
        } catch (\Exception $e) {
            throw new ParserException(previous: $e);
        }

        try {
            $text = '';
            while (!$f->eof()) {
                $text = \mb_trim($f->fgets());
                if ('' !== $text) {
                    break;
                }
            }
        } finally {
            unset($f); // close file
        }

        return new TxtMeta($text);
    }
}
