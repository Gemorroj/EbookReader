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

    /**
     * Reads a leading slice for sniffing. When the slice contains a line break,
     * it is cut there so it never ends inside a multibyte character.
     */
    private function readTextHead(\SplFileObject $file, int $size): string
    {
        $head = $file->fread($size);
        if (false === $head) {
            throw new ParserException();
        }

        $pos = \strrpos($head, "\n");
        if (false !== $pos) {
            $head = \substr($head, 0, $pos);
        }

        return $head;
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
            $this->getTextDecoder()->toUtf8($this->readTextHead($f, 4096));
        } catch (\Throwable $e) {
            return false;
        } finally {
            unset($f); // close file
        }

        return true;
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

        $text = \mb_trim($this->getTextDecoder()->toUtf8($text));

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
            $head = $this->getTextDecoder()->toUtf8($this->readTextHead($f, 8192));
        } finally {
            unset($f); // close file
        }

        $title = '';
        foreach (\explode("\n", $head) as $line) {
            $title = \mb_trim($line);
            if ('' !== $title) {
                break;
            }
        }

        return new TxtMeta($title);
    }
}
