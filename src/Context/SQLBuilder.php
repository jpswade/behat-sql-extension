<?php

namespace Genesis\SQLExtension\Context;

use Behat\Gherkin\Node\TableNode;
use Exception;

class SQLBuilder implements Interfaces\SQLBuilderInterface
{
    /**
     * Holds columns converted to array.
     */
    private $columns = [];

    /**
     * External references, format of an external ref [...|...:...].
     */
    private $refs = [];

    /**
     * Constructs a clause based on the glue, to be used for where and update clause.
     *
     * @param string $commandType
     * @param string $glue
     * @param array $columns
     *
     * @return string
     */
    public function constructSQLClause($commandType, $glue, array $columns)
    {
        $whereClause = [];

        foreach ($columns as $column => $value) {
            $newValue = ltrim($value, '!');
            $quotedValue = $this->quoteOrNot($newValue);
            $comparator = $this->getComparatorFromValue(
                $value,
                $glue,
                $commandType
            );

            // Make up the sql.
            $clause = sprintf(
                '`%s` %s %s',
                $column,
                $comparator,
                $quotedValue
            );

            $whereClause[] = $clause;
        }

        return implode($glue, $whereClause);
    }

    /**
     * Gets the comparator based on the value provided.
     * This could be =, LIKE, != or something else based on the value.
     *
     * @param string $value The value that holds the comparator info.
     * @param string $glue The glue used for the clause construction.
     * @param string $commandType The command type being constructed.
     *
     * @return string
     */
    private function getComparatorFromValue($value, $glue, $commandType)
    {
        $comparator = '%s=';
        $notOperator = '';
        $newValue = ltrim($value, '!');

        // Check if the supplied value is null and that the construct is not for insert and update,
        // if so change the format.
        if (strtolower($newValue) == 'null' and
            trim($glue) != ',' and
            in_array($commandType, ['update', 'select', 'delete'])) {
            $comparator = 'is%s';
        }

        // Check if a not is applied to the value.
        if (strpos($value, '!') === 0) {
            if (strtolower($newValue) == 'null' and
            trim($glue) != ',' and
            in_array($commandType, ['update', 'select', 'delete'])) {
                $notOperator = ' not';
            } else {
                $notOperator = '!';
            }
        }

        // Check if the value is surrounded by wildcards. If so, we'll want to use a LIKE comparator.
        if (preg_match('/^%.+%$/', $value)) {
            $comparator = 'LIKE';
        }

        return sprintf($comparator, $notOperator);
    }

    /**
     * Converts the incoming string param from steps to array.
     *
     * @param string $columns
     *
     * @return array
     */
    public function convertToArray($query)
    {
        // Temporary placeholder to protect escaped commas.
        $commaEscapeCode = '%|-|';
        $this->columns = [];
        // as a rule, each array element after this should have the ":" separator.
        // Would it be better to use preg_match here?
        $query = str_replace('\,', $commaEscapeCode, $query);
        $columns = explode(',', $query);

        foreach ($columns as $column) {
            if (strpos($column, ':') == false) {
                throw new Exception('Unable to explode columns based on ":" separator');
            }

            list($col, $val) = explode(':', $column, self::EXPLODE_MAX_LIMIT);

            $this->columns[trim($col)] = str_replace($commaEscapeCode, ',', trim($val));
        }

        return $this->columns;
    }

    /**
     * Quotes value if needed for sql.
     *
     * @param string $val
     *
     * @return string
     */
    public function quoteOrNot($val)
    {
        return ((is_string($val) || is_numeric($val)) and !$this->isNotQuotable($val)) ?
            sprintf(
                "'%s'",
                str_replace(
                    ['\\', "'"],
                    ['', "\\'"],
                    $val
                )
            ) :
            $val;
    }

    /**
     * Checks if the value isn't a keyword.
     *
     * @param string $val
     *
     * @return bool
     */
    private function isNotQuotable($val)
    {
        $keywords = [
            'true',
            'false',
            'null',
            'NOW\(\)',
            'COUNT\(.*\)',
            'MAX\(.*\)',
            'DATE\(.*\)',
            '\d+'
        ];

        $keywords = array_merge($keywords, $_SESSION['behat']['GenesisSqlExtension']['notQuotableKeywords']);

        // Check if the val is a keyword
        foreach ($keywords as $keyword) {
            if (preg_match(sprintf('/^%s$/is', $keyword), $val)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param TableNode $node The node with all fields and data.
     *
     * @return array The queries built of the TableNode.
     */
    public function convertTableNodeToQueries(TableNode $node)
    {
        // Get all rows and extract the heading.
        $rows = $node->getRows();

        if (! $rows || !isset($rows[1])) {
            throw new Exception('No data provided to loop through.');
        }

        // Get the title row.
        $columns = $rows[0];
        unset($rows[0]);

        $queries = [];

        // Loop through the rest of the rows and form up the queries.
        foreach ($rows as $row) {
            $query = '';
            foreach ($row as $index => $value) {
                $query .= sprintf('%s:%s,', $columns[$index], $value);
            }
            $queries[] = trim($query, ',');
        }

        return $queries;
    }

    /**
     * @param TableNode $node The node with all fields and data.
     *
     * @return string The queries built of the TableNode.
     */
    public function convertTableNodeToSingleContextClause(TableNode $node)
    {
        // Get all rows and extract the heading.
        $rows = $node->getRows();

        if (! $rows || !isset($rows[1])) {
            throw new Exception('No data provided to loop through.');
        }

        // Get rid of the top row as its just represents the title.
        unset($rows[0]);

        $clauseArray = [];
        // Loop through the rest of the rows and form up the queries.
        foreach ($rows as $row) {
            $clauseArray[] = implode(':', $row);
        }

        return implode(',', $clauseArray);
    }

    /**
     * returns sample data for a data type.
     *
     * @param string $type
     *
     * @return string|bool
     */
    public function sampleData($type)
    {
        switch (strtolower($type)) {
            case 'boolean':
                return 'false';
            case 'integer':
            case 'double':
            case 'int':
                return rand();
            case 'tinyint':
                return rand(0, 9);
            case 'string':
            case 'text':
            case 'varchar':
            case 'character varying':
            case 'tinytext':
            case 'longtext':
                return $this->quoteOrNot(sprintf("behat-test-string-%s", time()));
            case 'char':
                return "'f'";
            case 'timestamp':
            case 'timestamp with time zone':
                return 'NOW()';
            case 'null':
                return null;
            default:
                return $this->quoteOrNot(sprintf("behat-test-string-%s", time()));
        }
    }

    /**
     * Returns the columns stored after conversion to array.
     *
     * @return array
     */
    public function getColumns()
    {
        return $this->columns;
    }

    /**
     * Get reference for a placeholder.
     *
     * @param string $placeholder The placeholder string.
     *
     * @return string|false Placeholder ref or false if the placeholder is not found.
     */
    public function getRefFromPlaceholder($placeholder)
    {
        if (strpos($placeholder, 'ext-ref-placeholder_') === false) {
            return false;
        }

        list($garbage, $index) = explode('_', $placeholder);
        unset($garbage);

        if (! array_key_exists($index, $this->refs)) {
            return false;
        }

        return $this->refs[$index];
    }

    /**
     * Check if the value provided is an external ref.
     *
     * @param string $value The value to check.
     *
     * @return bool
     */
    public function isExternalReference($value)
    {
        // [user.id|user.email: its.inevitable@hotmail.com]
        // [woody_crm.users.id|email:its.inevitable@hotmail.com,status:1]
        $externalRefPattern = '#^(\[[^\]]+\|(.+\:.+)+\])$#';
        if (preg_match($externalRefPattern, $value)) {
            return true;
        }

        return false;
    }

    /**
     * @param string $value The value to check.
     *
     * @return bool
     */
    public function isExternalReferencePlaceholder($value)
    {
        if (strpos($value, 'ext-ref-placeholder_') === 0) {
            return true;
        }

        return false;
    }

    /**
     * Get single query for the external reference.
     *
     * @param string $externalRef The external ref enclosed in [].
     *
     * @return string
     */
    public function getSQLQueryForExternalReference($externalRef)
    {
        if (! $this->isExternalReference($externalRef)) {
            throw new Exception(
                'Invalid external ref provided, external ref must be enclosed in "[]" and criteria split by "|".
                Example format [{table}.{column1}|{column2}:{value}]'
            );
        }

        list($columnAndTable, $criteria) = explode('|', trim($externalRef, '[]'));

        // Get the table and column names.
        $table = null;
        preg_match('#.+(?=\.)#', $columnAndTable, $table);
        $array = explode('.', $columnAndTable);
        $column = end($array);

        // Construct where clause.
        $whereClause = $this->constructSQLClause('SELECT', ' AND ', $this->convertToArray($criteria));

        return sprintf('SELECT %s FROM %s WHERE %s', $columnAndTable, $table[0], $whereClause);
    }

    /**
     * Get placeholder for reference.
     *
     * @param string $externalRef The reference string.
     *
     * @return string The placeholder.
     */
    private function getPlaceholderForRef($externalRef)
    {
        // Search for existing refs.
        $this->refs[] = $externalRef;
        $index = array_search($externalRef, $this->refs);

        return sprintf('ext-ref-placeholder_%d', $index);
    }

    /**
     * parseExternalQueryReferences.
     *
     * @param string $query
     *
     * @return string
     */
    public function parseExternalQueryReferences($query)
    {
        // Extract all matches for external refs.
        $pattern = '#(\[[^\]]+\|.+?\]+?)#';
        $refs = [];
        preg_match_all($pattern, $query, $refs);

        // If there are any external ref matches, then replace them with placeholders.
        if (isset($refs[0])) {
            foreach ($refs[0] as $ref) {
                $placeholder = $this->getPlaceholderForRef($ref);
                $query = str_replace($ref, $placeholder, $query);
            }
        }

        // Return query with potential placeholders.
        return $query;
    }
}
