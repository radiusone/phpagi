<?php

namespace PhpAgi\AGI;

use ArrayAccess;
use LogicException;
use PhpAgi\Enums\AgiResult;
use ValueError;

/**
 * @phpstan-type AgiArray array{code:-1|200|510|511|520, result:string, data?:string, endpos?:int}
 */
class Response implements ArrayAccess
{
    protected static array $arrayables = ['code', 'result', 'data', 'endpos'];
    /**
     * @param AgiResult $code
     * @param numeric-string $result
     * @param string|null $data
     * @param int|null $endpos
     */
    public function __construct(public AgiResult $code, public string $result, public ?string $data = null, public ?int $endpos = null)
    {
    }

    /**
     * @return AgiArray
     */
    public function toArray(): array
    {
        return array_filter([
            'code' => $this->code->value,
            'result' => $this->result,
            'data' => $this->data,
            'endpos' => $this->endpos,
        ], fn ($v) => !is_null($v));
    }

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, self::$arrayables);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->offsetExists($offset) ? $this->$offset : throw new ValueError();
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($this->offsetExists($offset)) {
            $this->$offset = $value;
        }
        throw new ValueError();
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException();
    }
}
