import React from 'react';
import Preceptores from './Preceptores';

// El módulo agrupa las dos cuentas de la preceptoría: jefe de preceptores y prosecretaría.
const JefePreceptores = () => (
  <Preceptores role="jefe_preceptores" roles={['jefe_preceptores', 'prosecretaria']} />
);

export default JefePreceptores;
