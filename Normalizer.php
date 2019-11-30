<?php

namespace Vayes\Serializer;

use Symfony\Component\OptionsResolver\OptionsResolver;
use function Vayes\Inflector\slugify;

class Normalizer
{
    const INCLUDE_PROTECTED_PROPERTIES = 'includeProtectedProperties';
    const INCLUDE_NULL_VALUES = 'includeNullValues';
    const PROPERTY_CALLBACK = 'propertyCallback';
    const IGNORED_PROPERTIES = 'ignoredProperties';
    const CONVERT_PROPERTIES_TO_SNAKE_CASE = 'convertPropertiesToSnakeCase';
    const CASE_CONVERTER_FUNCTION = 'caseConverterFunction';
    const IGNORE_SQL_BEHAVIOURAL_PROPERTIES = 'ignoreSqlBehaviouralProperties';
    const SQL_BEHAVIOURAL_PROPERTIES =  [
        'created_at', 'created_by',
        'updated_at', 'updated_by',
        'deleted_at', 'deleted_by'
    ];

    /** @var array */
    protected $options = [];

    /**
     * Normalizer constructor.
     *
     * @param array       $options
     */
    public function __construct(array $options = [])
    {
        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);
        $this->handleSqlBehaviouralProperties($options);
        $this->options = $resolver->resolve($options);
    }

    /**
     * Converts object to array recursively
     * Accepts callbacks. Defined callbacks are:
     *      'callbackPropertyCase'      => string 'functionName'
     *      'callbackExcludeProperty'   => array ['propertyName1', 'propertyName2']
     *
     * For ease of use, callback params are defined as Enum at PrimeEnum:
     * --> PrimeEnum::NORMALIZER_PROPERTY_EXCLUDE => ['id', 'name']
     * --> PrimeEnum::NORMALIZER_PROPERTY_CALLBACK => 'myFunc|arg_2|arg_3|arg_4'
     * --> PrimeEnum::NORMALIZER_PROPERTY_CASE_AUTO_CONVERT => false OR myFunc|arg_2|arg_3|...
     *
     * Notes:
     *
     * --> If no option is set to AUTO_CONVERT (camel|title => snake), default is true,
     *     and it calls PrimeEnum::NORMALIZER_PROPERTY_DEFAULT_CASE_CONVERTER function.
     *
     * --> NORMALIZER_PROPERTY_CALLBACK function takes $key parameter first (as $arg_1).
     *     i.e. str_replace|arg_2|arg_3 will works as str_replace($key, $arg_2, $arg_3)
     *          which will throw error due to argument ordering. :) Be careful!
     *
     *
     * Regex: /\\x00.*?\\x00/u clears all.
     *
     * @param       $obj
     * @param array $ignoredProperties
     * @return array
     */
    function normalize($obj, array $ignoredProperties = []): array
    {
        $cst = (array) $obj;
        $arr = [];
        $protected = [];

        foreach ($cst as $k => $v)
        {
            // Resolve protected properties
            $key = preg_replace('/\\x00\*\\x00/u', '', $k);
            if ((string) $key !== (string) $k) {
                $protected[] = $key;
                if (false === $this->options[self::INCLUDE_PROTECTED_PROPERTIES]) {
                    continue;
                }
            }

            // Resolve private|public properties
            $key = preg_replace('/\\x00.*?\\x00/u', '', $key);
            
            // Normalize DateTime objects
            if (true === $v instanceof \DateTimeInterface) {
                $v->setTimezone(new \DateTimeZone('UTC'));
                $v = $v->format("Y-m-d h:i:s");
            }
            
            $val = (is_array($v) || is_object($v)) ? $this->normalize($v) : $v;

            if (false === $this->options[self::INCLUDE_NULL_VALUES]) {
                if (null === $val) {
                    continue;
                }
            }

            // Handle case conversion of the property names
            if (true === $this->options[self::CONVERT_PROPERTIES_TO_SNAKE_CASE]) {
                $funcMetaArray = explode('|', $this->options[self::CASE_CONVERTER_FUNCTION]);
                $func = array_shift($funcMetaArray);

                if (false === function_exists($func)) {
                    throw new \BadMethodCallException("Function with `{$func}` could not be found");
                }

                $key = call_user_func_array(
                    $func,
                    array_merge(
                        (array) $key,
                        $funcMetaArray
                    )
                );
            }

            // String manipulation callbacks for keys. e.g. "str_replace|arg_2|arg_3"
            $cbPropertyOpt = 'callbackPropertyCallback';
            if (false === is_null($this->options[self::PROPERTY_CALLBACK])) {
                if (is_string($this->options[self::PROPERTY_CALLBACK])) {
                    $cbArray = explode('|', $this->options[self::PROPERTY_CALLBACK]);
                    $cbFunc = array_shift($cbArray);
                    $key = call_user_func_array(
                        $cbFunc,
                        array_merge(
                            (array) $key,
                            $cbArray
                        )
                    );
                } else {
                    $cbFunc = $this->options[self::PROPERTY_CALLBACK];
                    $key = call_user_func_array(
                        $cbFunc,
                        [$key, $val]
                    );

                    if (null === $key OR false === $key) {
                        continue;
                    }
                }
            }

            $arr[$key] = $val;

            // Exclude given keys from normalized object. e.g. unset id fields
            if (false === empty($this->options[self::IGNORED_PROPERTIES])
                && true === is_array($this->options[self::IGNORED_PROPERTIES])) {
                if (in_array($key, $this->options[self::IGNORED_PROPERTIES])) {
                    unset($arr[$key]);
                }
            }

            // This enables to ignore elements even with use this class as Facade.
            if (false === empty($ignoredProperties)) {
                if (in_array($key, $ignoredProperties)) {
                    unset($arr[$key]);
                }
            }
        }

        return (array) $arr;
    }

    /**
     * Adds optional sql behavioural properties to Ignore list
     * @param array $options
     */
    protected function handleSqlBehaviouralProperties(array &$options)
    {
        if (false === empty($options[self::IGNORE_SQL_BEHAVIOURAL_PROPERTIES])) {
            if (true === isset($options[self::IGNORED_PROPERTIES])) {
                $options[self::IGNORED_PROPERTIES] = array_merge(
                    $options[self::IGNORED_PROPERTIES], self::SQL_BEHAVIOURAL_PROPERTIES
                );
            } else {
                $options[self::IGNORED_PROPERTIES] = self::SQL_BEHAVIOURAL_PROPERTIES;
            }
        }
    }

    protected function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            self::INCLUDE_PROTECTED_PROPERTIES => false,
            self::INCLUDE_NULL_VALUES => false,
            self::PROPERTY_CALLBACK => null,
            self::IGNORED_PROPERTIES => [],
            self::CONVERT_PROPERTIES_TO_SNAKE_CASE => true,
            self::CASE_CONVERTER_FUNCTION => 'vayes\str\str_snake_case_safe|_',
            self::IGNORE_SQL_BEHAVIOURAL_PROPERTIES => false
        ]);

        $resolver->setAllowedTypes(self::INCLUDE_PROTECTED_PROPERTIES, 'bool');
        $resolver->setAllowedTypes(self::INCLUDE_NULL_VALUES, 'bool');
        $resolver->setAllowedTypes(self::PROPERTY_CALLBACK, ['null', 'string', 'Closure']);
        $resolver->setAllowedTypes(self::IGNORED_PROPERTIES, 'string[]');
        $resolver->setAllowedTypes(self::CONVERT_PROPERTIES_TO_SNAKE_CASE, 'bool');
        $resolver->setAllowedTypes(self::CASE_CONVERTER_FUNCTION, 'string');
        $resolver->setAllowedTypes(self::IGNORE_SQL_BEHAVIOURAL_PROPERTIES, 'bool');
    }
}
