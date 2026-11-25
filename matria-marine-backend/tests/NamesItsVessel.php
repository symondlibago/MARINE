<?php

namespace Tests;

/**
 * Every inventory request names the vessel it is for.
 *
 * The module began with one ship and resolved it by taking the only one there
 * was. With a fleet that fallback would serve the wrong vessel's stock without
 * saying so, so the API now refuses a request that does not name one — and
 * these tests have to name one too.
 *
 * The class using this provides $this->vessel.
 */
trait NamesItsVessel
{
    /**
     * An inventory URL with the vessel attached.
     *
     * Laravel reads query parameters and body alike through input(), so this
     * works for a POST as well as a GET and the body stays about the payload.
     */
    protected function url(string $path): string
    {
        $separator = str_contains($path, '?') ? '&' : '?';

        return '/api/inventory'.$path.$separator.'vessel_id='.$this->vessel->id;
    }
}
