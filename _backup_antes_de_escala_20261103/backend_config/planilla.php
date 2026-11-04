<?php

// Textos de la planilla de calificaciones.
//
// Se imprimen en el encabezado de la planilla oficial. Si cambia el nombre de
// la escuela o el ORGANISMO, se cambia acá y las planillas salen con el texto
// nuevo (no hay que tocar el HTML).
//
// Para cambiarlo sin editar este archivo se pueden definir estas variables de
// entorno en el .env (ganan sobre los valores de acá):
//
//   PLANILLA_ENCABEZADO_1="Gobierno de la Provincia de Buenos Aires"
//   PLANILLA_ENCABEZADO_2="Dirección Provincial de Educación Secundaria"
//   PLANILLA_ENCABEZADO_3="E.E.S.T. N° 2"
//
// Y el nombre completo de la escuela va en PLANILLA_ESCUELA (tercer renglón).

return [

    // Renglón 1 (Verdana 9 negrita)
    'encabezado_1' => env('PLANILLA_ENCABEZADO_1', 'Gobierno de la Provincia de Buenos Aires'),

    // Renglón 2 (Verdana 9 negrita)
    'encabezado_2' => env('PLANILLA_ENCABEZADO_2', 'Dirección Provincial de Educación Secundaria'),

    // Renglón 3 (Verdana 12 negrita): nombre completo de la escuela.
    // Por defecto usa el mismo texto que ya usa el sistema.
    'escuela' => env('PLANILLA_ESCUELA', 'E.E.S.T N° 2'),

    // Aparece entre comillas en el renglón 3, como en la planilla oficial.
    'escuela_bajada' => env('PLANILLA_ESCUELA_BAJADA', ''),

    // Rótulo del recuadro central (el año lo agrega el sistema).
    'titulo' => env('PLANILLA_TITULO', 'PLANILLA DE CALIFICACIONES'),

    // Plantilla de Excel oficial de la escuela. La descarga se arma sobre este
    // archivo, así el diseño y la configuración de impresión son exactamente los
    // del formulario de papel. Cuando actualicen el formulario, se reemplaza este
    // .xlsx y ya está (no hay que tocar código).
    // Está en archivos/, al lado del backend, junto con los libros de planillas.
    'plantilla_xlsx' => env(
        'PLANILLA_PLANTILLA_XLSX',
        dirname(base_path()) . '/archivos/plantilla_calificaciones.xlsx'
    ),

];