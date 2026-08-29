<?php

namespace justinholtweb\donky\db;

/**
 * Donky's database tables.
 */
abstract class Table
{
    public const DESIGNS = '{{%donky_designs}}';
    public const TYPES = '{{%donky_documenttypes}}';
    public const COUNTERS = '{{%donky_counters}}';
    public const DOCUMENTS = '{{%donky_documents}}';
    public const RULES = '{{%donky_rules}}';
}
