<?php

namespace Arix\Cron;

interface FieldFactoryInterface
{
    public function getField(int $position): FieldInterface;
}
