<?php

namespace App\Support;

/**
 * Lectura segura de un campo de formulario que se espera de texto.
 *
 * Las pantallas mandan texto, pero un campo también puede llegar como lista o
 * como objeto: una extensión del navegador que rellena formularios, un pedido
 * cortado a mitad o un script que arma el cuerpo como le conviene. Los
 * controladores normalizan antes de validar —el DNI vacío, el mail en
 * minúscula, la división en mayúscula— y ahí `(string) $dato` con un arreglo
 * corta con "Array to string conversion": la validación nunca llega a correr y
 * la pantalla muestra "Algo se trabó de nuestro lado".
 *
 * Este helper devuelve el texto sin más: lo que no es texto se lee vacío y
 * queda a criterio de las reglas de validación, que responden 422 en español.
 */
class Texto
{
    /** El valor tal como vino, reducido a texto sin spaces sobrantes. */
    public static function plano(mixed $valor): string
    {
        if (is_string($valor)) {
            return trim($valor);
        }

        if (is_int($valor) || is_float($valor)) {
            return (string) $valor;
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '';
        }

        // Arreglos, objetos, recursos y null: para un campo de texto es lo
        // mismo que no haberlo escrito.
        return '';
    }

    /** Como plano() pero en minúsculas, para comparar o guardar un mail. */
    public static function minusculo(mixed $valor): string
    {
        return mb_strtolower(self::plano($valor), 'UTF-8');
    }
}
