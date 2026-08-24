<?php

namespace PhpAgi\AGI;

use ArrayAccess;
use LogicException;
use PhpAgi\Enums\AgiResult;
use ValueError;

class Response implements ArrayAccess
{
    /**
     * @param AgiResult $code
     * @param numeric-string $result
     * @param string|null $data
     */
    public function __construct(public AgiResult $code, public string $result, public ?string $data = null)
    {
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'result' => $this->result,
            'data' => $this->data,
        ];
    }

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, ['code', 'result', 'data']);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (in_array($offset, ['code', 'result', 'data'])) {
            return $this->$offset;
        }
        throw new ValueError();
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (in_array($offset, ['code', 'result', 'data'])) {
            $this->$offset = $value;
        }
        throw new ValueError();
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException();
    }
}
