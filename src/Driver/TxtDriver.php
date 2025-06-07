<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Cover\Cover;
use EbookReader\Data\TxtData;
use EbookReader\EbookCoverInterface;
use EbookReader\Exception\ParserException;
use EbookReader\Meta\TxtMeta;

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

    protected function getInternalFile(): string
    {
        if (!$this->internalFile) {
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
        }

        return $this->internalFile;
    }

    protected function isBinary(string $data): bool
    {
        return !\mb_check_encoding($data, 'UTF-8');
    }

    public function isValid(): bool
    {
        $result = true;
        try {
            $f = new \SplFileObject($this->getInternalFile(), 'r');
            $line1 = $f->fgets();
            $line2 = $f->fgets();
            $line3 = $f->fgets();
            if ($this->isBinary($line1.$line2.$line3)) {
                $result = false;
            }
        } catch (\Exception $e) {
            return false;
        } finally {
            unset($f); // close file
        }

        return $result;
    }

    /**
     * @return TxtData[]
     */
    public function getData(): array
    {
        try {
            $f = new \SplFileObject($this->getInternalFile(), 'r');
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
        $pos = \strpos($text, "\n");
        if (false !== $pos) {
            $title = \substr($text, 0, $pos);
            $title = \mb_trim($title);
        } else {
            $title = null;
        }

        return [
            new TxtData($text, $title, []),
        ];
    }

    public function getCover(): ?EbookCoverInterface
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
                    $mime = $this->getImageMimeDetector()->detect($fileContent);
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

    public function getMeta(): TxtMeta
    {
        try {
            $f = new \SplFileObject($this->getInternalFile(), 'r');
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
