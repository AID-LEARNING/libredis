<?php

namespace SenseiTarzan\libredis\Class;

enum ETypeRequest: string
{
    case STRING_CLASS = 'string_class';
    case CLOSURE = 'closure';
}
