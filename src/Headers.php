<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue;
class Headers
{
    public string $_id = '*';
    public int $_delay = 0;
    public float $_timestamp = 0.0;
    public int $_count = 0;
    public string $_error = '';
    public bool $_delete = true;

    public function __construct(string|array|null $headers = null)
    {
        if($headers !== null) {
            $this->init($headers);
        }
    }

    /**
     * @param string|array $headers
     * @return $this
     */
    public function init(string|array $headers): static
    {
        if(is_string($headers)) {
            $headers = $this->toArray($headers);
        }
        foreach ($headers as $key => $value) {
            if(\property_exists($this, $key)) {
                try {
                    $this->$key = $value;
                }catch (\Throwable $throwable){}
            }
        }
        return $this;
    }

    /**
     * @param string|null $data
     * @return array
     */
    public function toArray(string|null $data = null): array
    {
        return json_decode($data ?? $this->toString(),true);
    }

    /**
     * @param array|object|null $data
     * @return string
     */
    public function toString(array|object|null $data = null): string
    {
        return json_encode($data ?? $this, JSON_UNESCAPED_UNICODE);
    }
}