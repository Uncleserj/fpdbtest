<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    private string $identifier = '%%'; // или можно генерировать рандомную строку

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $count = substr_count( $query, '?' );

        if ( ! $count && ! $args ) return $query;

        if ( $count && ! $args ) throw new Exception( 'Нет аргументов' );

        if ( ! $count && $args ) throw new Exception( 'Нет спецификаторов' );

        $bindings = $this->collect_bindings( $query, $args );

        $has_conditions = strpos( $query, '{' ) !== false;
        
        if ( $has_conditions ) $this->format_conditions( $query, $args, $bindings );

        $this->format_query( $query, $this->map_bindings( $args, $bindings ), $has_conditions );

        return $query;
    }

    public function skip(): string
    {
        return $this->identifier;
    }

    private function map_bindings(array $args, array $binds, array $parts = [] ): array
    {
        $key = 0;
        foreach( $binds as $pos => $bind ) {

            $value = $args[$key];
            $type = gettype( $value );

            if ( $type === 'boolean' ) {
                $type = 'integer';
                $value = (int) $value;
            }

            switch( $bind ) {
                case '?d':
                    if ( ! ( $type === "integer" || $type === "NULL" ) ) throw new Exception( 'Неверный тип аргумента: ?d === ' . $type );
                    $value = $this->format_value( $value, $type);
                break;
                case '?f':
                    if ( ! ( $type === "double" || $type === "NULL" ) ) throw new Exception( 'Неверный тип аргумента: ?f === ' . $type );
                    $value = $this->format_value( $value, $type );
                break;
                case '?a':
                    if ( $type !== "array" ) throw new Exception( 'Неверный тип аргумента: ?a === ' . $type );
                    if ( array_is_list( $value ) ) {
                        $value = array_map( fn( $v ) => $this->format_value( $v, gettype( $v ) ), $value );
                    } else {
                        $value = array_map( fn( $k, $v ) => $this->format_value( $v, gettype( $v ), $k ), array_keys( $value ), $value );
                    }
                    $value = implode( ', ', $value );
                break;
                case '?#':
                    if ( ! ( $type === "array" || $type === "string" ) ) throw new Exception( 'Неверный тип аргумента: ?# === ' . $type );
                    $value = $type === "array" ? implode( ', ', array_map( fn( $v ) => '`' . $v . '`', $value ) ) : '`' . $value . '`';
                break;
                case '?':
                    $value = $this->format_value( $value, $type );
                break;
                default: throw new Exception( 'Неверный спецификатор' ); break;
            }

            $parts[] = [$value, $pos, strlen( $bind )];
            $key++;
        }

        return $parts;
    }

    private function format_query(string &$query, array $parts, bool $has_conditions = false)
    {
        krsort( $parts );
        foreach( $parts as $part ) {
            $query = substr_replace( $query, $part[0], $part[1], $part[2] );
        }
        if ( $has_conditions ) $query = preg_replace( '/(%(_+)%)+/', '', str_replace( ['{', '}'], '', $query ) );
    }

    private function format_value(string|int|float|bool|null $value, string $type, string $key = ''): string
    {
        if ( ! ( $type === "boolean" || $type === "string" || $type === "integer" || $type === "double" || $type === "NULL"  ) ) throw new Exception( 'Неверный тип аргумента: ? === ' . $type );
        if ( $key && ( gettype( $key ) !== 'string' || ! preg_match( '/^[a-zA-Z0-9_]+$/', $key ) ) ) throw new Exception( 'Неверный идентификаторов' );
        if ( $type === 'boolean' ) {
            $value = $key ? '`' . $key . '`' . ' = ' . (int) $value : (int) $value;
        } elseif ( $type === 'NULL' ) {
            $value = $key ? '`' . $key . '`' . ' = ' . 'NULL' : 'NULL';
        } elseif ( $type === 'string' ) {
            $value = $key ? '`' . $key . '`' . ' = ' . "'" . addslashes( $value ) . "'" : "'" . addslashes( $value ) . "'";
        }
        return $value;
    }

    private function collect_bindings(string $query, array $args, array $bindings = [] ): array
    {
        preg_match_all( '/(\?[d|f|a|#]?)/', $query, $matches, PREG_OFFSET_CAPTURE );

        if ( count( $matches[0] ) !== count( $args ) ) throw new Exception( 'Неверное число спецификаторов / аргументов в запросе' );
               
        foreach( $matches[0] as $binding ) {
            $bindings[ $binding[1] ] = $binding[0];
        }

        return $bindings;
    }

    private function format_conditions(string &$query, array &$args, array &$bindings )
    {
        preg_match_all( '/{(.*?)}+/', $query, $matches );

        if ( $matches[0] ) {

            $parts = $excluded = [];
            $offset = 0;

            foreach( $matches[0] as $condition ) {
                $condition_len = strlen( $condition );
                $condition_body = substr( $condition, 1, $condition_len - 2 );
                
                if ( strpos( $condition_body, '{' ) !== false || strpos( $condition_body, '}' ) ) throw new Exception( 'Условные блоки не могут быть вложенными' );
                
                $condition_start = strpos( $query, $condition, $offset );
                $condition_end = $condition_start + $condition_len;
                $offset = $condition_end;

                $found = false;
                $key = 0;
                foreach( $bindings as $pos => $binding ) {
                    if ( $pos > $condition_start && $pos < $condition_end && $args[$key] === $this->identifier ) {
                        $found = true;

                        $placeholder = '%';
                        for( $i = 0; $i < $condition_len - 2; $i++ ) {
                            $placeholder .= '_';
                        }
                        $placeholder .= '%';

                        $parts[] = [$placeholder, $condition_start, $condition_len];
                        break;
                    }
                    $key++;
                }

                if ( $found ) {
                    $key = 0;
                    foreach( $bindings as $pos => $bind ) {
                        if ( $pos > $condition_start && $pos < $condition_end ) $excluded[$key] = $pos;
                        $key++;
                    }
                }

            }

            $this->format_query($query, $parts);

            foreach( $excluded as $key => $pos ) {
                unset($args[$key]);
                unset($bindings[$pos]);
            }
            
            $args = array_values( $args );
        }
    }
}
