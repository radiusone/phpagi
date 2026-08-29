<?php

namespace PhpAgi\AGI;

use Generator;
use InvalidArgumentException;

/**
 * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/speech_recognize/
 */
class SpeechRecognizeResponse extends Response
{
    /** @var string|null */
    public ?string $digit = null;

    /** @var int|null */
    public ?int $results = null;

    /** @var string[]|null  */
    protected ?array $scores = null;

    /** @var string[]|null  */
    protected ?array $texts = null;

    /** @var string[]|null  */
    protected ?array $grammars = null;

    public function __get(string $name): string|null
    {
        if (preg_match('/^(score|text|grammar)(\d+)$/', $name, $m)) {
            $store = $m[1] . 's';
            $i = $m[2];

            return $this->{$store}[$i] ?? null;
        }

        return $this->$name;
    }

    public function __set(string $name, mixed $value): void
    {
        if (preg_match('/^(score|text|grammar)(\d+)$/', $name, $m)) {
            $store = $m[1] . 's';
            $i = $m[2];
            $this->{$store} ??= [];
            $this->{$store}[$i] = $value;
        } else {
            $this->$name = $value;
        }
    }

    public function toArray(): array
    {
        $arr = parent::toArray();
        foreach ($this->scores as $i => $score) {
            $arr['score' . $i] = $score;
            $arr['text' . $i] = $this->texts[$i];
            $arr['grammar' . $i] = $this->grammars[$i];
        }

        return $arr;
    }

    public function getResults(): Generator
    {
        foreach ($this->scores as $i => $score) {
            yield [
                'score' . $i => $score,
                'text' . $i => $this->texts[$i],
                'grammar' . $i => $this->grammars[$i],
            ];
        }
    }
}
