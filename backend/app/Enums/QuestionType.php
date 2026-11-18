<?php

namespace App\Enums;

enum QuestionType: string
{
    case McqSingle = 'mcq_single';
    case McqMulti = 'mcq_multi';
    case TrueFalse = 'true_false';
    case ShortText = 'short_text';
}
