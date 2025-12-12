<?php
namespace mini\Table;

enum Operator: string {
    case Eq = '=';
    case Lt = '<';
    case Lte = '<=';
    case Gt = '>';
    case Gte = '>=';
    case In = 'IN';
    case Like = 'LIKE';
}