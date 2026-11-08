// Pestaña "Roles y permisos" del módulo Usuarios.
// Un bloque por módulo del sistema: si aparece en el menú, qué puede ver del
// módulo, qué puede modificar y qué acciones tiene. Solo entra el administrador
// y el jefe de preceptores.
//
// Reglas que refleja la pantalla (las mismas del backend):
//  - Todos los roles se editan igual: la lista de acciones marcada es la lista
//    del rol, sin tope de rango. El rango decide con qué permisos nace el rol,
//    su alcance de fábrica y quién puede modificarlo.
//  - Lo único que no se puede desmarcar es la gestión de roles (Admin y Jefe de
//    Preceptores) y el piso del Admin, para que la única cuenta no se cierre afuera.
import React, { useEffect, useMemo, useState, useCallback } from "react";
import useCierreAnimado from "../../compartido/lib/useCierreAnimado";
import { ShieldCheck, Plus, Save, Trash2, RotateCcw, Users, Lock, Check, X, Search, Eye, PencilLine, Layers, ChevronDown, ChevronRight } from "lucide-react";
import { apiRolesConfig, apiCrearRol, apiEditarRol, apiEliminarRol } from "../../compartido/api/roles";
import { setRoleDetalle, cargarRoleDetalle, rolLabel } from "../auth/roles";
import { useToast } from "../../compartido/componentes/Common/Toast";
import useConfirmAction from "../../compartido/hooks/useConfirmAction";
import useAviso from "../../compartido/componentes/modals/useAviso";
import { mensajeAmable } from "../../compartido/lib/errores";
import "./RolesPermisos.css";

const NIVEL_LABEL = {
  1: "Profesor",
  2: "Preceptor",
  3: "Jefe / Prosecretaría",
  4: "Directivo",
  5: "Administrador",
};

// Permisos que solo tienen el admin y el jefe de preceptores (el backend usa la
// misma lista y la pantalla no deja ni marcarlos ni quitarlos).
const SOLO_SISTEMA = new Set(["roles.configurar"]);
const ROLES_GESTION = ["admin", "jefe_preceptores"];

// Un bloque por módulo: los datos del módulo se acotan con el alcance del área.
// "Ver" y "modificar" nunca quedan por encima del alcance de lectura.
const ALCANCE_ETIQUETA = { todos: "Todos", asignados: "Asignados", ninguno: "Nada" };
const pesoAlcance = { ninguno: 0, asignados: 1, todos: 2 };

// Copia el mapa efectivo de alcance del rol para poder editarlo y comparar.
const clonarAlcance = (mapa) => {
  const out = {};
  Object.entries(mapa || {}).forEach(([area, regla]) => {
    out[area] = { ver: regla.ver, editar: regla.editar };
  });
  return out;
};

// Módulos que el rol tiene hoy: si no está configurado, los que le tocan por rango.
const modulosBase = (rol) => rol?.modulos ?? rol?.modulos_defecto ?? [];

export default function RolesPermisos() {
  const [confirmAction, confirmModal] = useConfirmAction();
  const [mostrarAviso, , avisoModal] = useAviso();
  const showToast = useToast();

  const [dato, setDato] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState("");
  const [selRol, setSelRol] = useState(null);
  const [edicion, setEdicion] = useState(null);
  // Módulos que el usuario contrajo en la pantalla; arrancan todos abiertos.
  const [cerrados, setCerrados] = useState(() => new Set());
  const [buscador, setBuscador] = useState("");
  const [guardando, setGuardando] = useState(false);
  const [nuevoAbierto, setNuevoAbierto] = useState(false);
  const [nuevo, setNuevo] = useState({ nombre: "", nivel: 1, plantilla: "profesor", desde: "", descripcion: "" });
  const { montado: montadoNuevo, cerrando: cerrandoNuevo } = useCierreAnimado(nuevoAbierto);

  const cargar = useCallback(async (mantener = true) => {
    setCargando(true);
    setError("");
    try {
      const res = await apiRolesConfig();
      setDato(res);
      setSelRol((actual) => {
        const siguiente = mantener && actual && res.roles.some((r) => r.rol === actual)
          ? actual
          : (res.roles.find((r) => r.tipo === "personalizado")?.rol || res.roles[res.roles.length - 1]?.rol || null);
        return siguiente;
      });
    } catch (e) {
      setError(mensajeAmable(e, "No se pudo cargar la configuración de roles."));
    } finally {
      setCargando(false);
    }
  }, []);

  useEffect(() => {
    cargar();
  }, [cargar]);

  const rol = useMemo(
    () => (dato?.roles || []).find((r) => r.rol === selRol) || null,
    [dato, selRol]
  );
  const esSistema = rol?.tipo === "sistema";
  // El backend marca qué roles están al alcance de quien edita (un jefe no toca
  // al admin ni a otro jefe). Si no viene el flag, se asume editable.
  const editable = rol ? rol.editable !== false : true;
  // Nadie puede darle a un rol más rango del que tiene quien lo edita.
  const miNivel = Number(dato?.mi_nivel || 0);
  const nivelesPosibles = [1, 2, 3, 4, 5].filter((n) => n <= miNivel || n === rol?.nivel);
  // Lo único que el Admin no puede desmarcar: sin esto no volvería a Usuarios.
  const pisoAdmin = useMemo(() => new Set(dato?.piso_admin || []), [dato]);
  // Casillas que no se ofrecen para un rol, porque el backend no las acepta fuera
  // de quien las tiene de verdad:
  //  - los respaldos (bajar o reemplazar la base) solo viven en el rol Admin;
  //  - la gestión de roles solo la tienen Admin y Jefe por su nombre de rol, así
  //    que en un rol personalizado la casilla no marcaría nada.
  const ocultasDe = useCallback((r) => {
    const set = new Set();
    if (r?.rol !== "admin") (dato?.solo_admin || []).forEach((c) => set.add(c));
    if (r?.tipo === "personalizado") SOLO_SISTEMA.forEach((c) => set.add(c));
    return set;
  }, [dato]);
  const ocultas = useMemo(() => ocultasDe(rol), [ocultasDe, rol]);

  // Un bloque por módulo: sus acciones, el área de datos y las entradas del menú.
  const bloquesCrudos = useMemo(() => dato?.bloques || [], [dato]);
  const bloques = useMemo(
    () => bloquesCrudos
      .map((b) => ({ ...b, items: b.items.filter((i) => !ocultas.has(i.clave)) }))
      .filter((b) => b.items.length > 0),
    [bloquesCrudos, ocultas]
  );
  // Lista completa de claves del catálogo: es el total sobre el que se cuenta.
  const todas = useMemo(
    () => bloques.flatMap((b) => b.items.map((i) => i.clave)),
    [bloques]
  );
  const totalCatalogo = useMemo(
    () => bloquesCrudos.reduce((n, b) => n + b.items.length, 0),
    [bloquesCrudos]
  );
  const etiquetasAlcance = dato?.etiquetas_alcance || ALCANCE_ETIQUETA;
  // Los módulos del menú los aportan los bloques: cada acción transversal vive
  // dentro del módulo que la usa, así no hay dos listas que se desincronicen.
  const modulosCatalogo = useMemo(() => {
    const out = {};
    bloques.forEach((b) => b.modulos.forEach((m) => { out[m.id] = m.nombre; }));
    return out;
  }, [bloques]);
  const totalModulos = Object.keys(modulosCatalogo).length;
  const areasConDatos = bloques.filter((b) => b.area).length;

  // El estado editable arranca con lo que el rol tiene hoy.
  useEffect(() => {
    if (!rol) {
      setEdicion(null);
      return;
    }
    // Lo que el rol tiene hoy, sin las casillas que no se le muestran: si no se
    // filtraran, Guardar las mandaría de vuelta y el rol quedaría con un permiso
    // que nadie puede usar ni ver.
    const activos = (rol.efectivos ?? rol.permitidos ?? []).filter((c) => !ocultas.has(c));
    setEdicion({
      nombre: rol.nombre,
      nivel: rol.nivel,
      descripcion: rol.descripcion || "",
      activos: new Set(activos),
      alcance: clonarAlcance(rol.alcance),
      modulos: new Set(modulosBase(rol)),
    });
    setCerrados(new Set());
  }, [rol?.rol, rol?.nivel]); // eslint-disable-line react-hooks/exhaustive-deps

  // Lo que el rol tiene guardado, en las tres dimensiones de cada bloque.
  const base = useMemo(() => ({
    acciones: new Set(rol?.efectivos ?? rol?.permitidos ?? []),
    alcance: rol?.alcance || {},
    modulos: new Set(modulosBase(rol)),
  }), [rol]);

  // Cambios sin guardar por bloque: acciones + alcance + visibilidad en el menú.
  const desvioBloque = useCallback((b) => {
    if (!edicion) return 0;
    let n = 0;
    b.items.forEach((i) => {
      if (base.acciones.has(i.clave) !== edicion.activos.has(i.clave)) n++;
    });
    if (b.area) {
      const g = base.alcance[b.area] || { ver: "ninguno", editar: "ninguno" };
      const h = edicion.alcance[b.area] || g;
      if (g.ver !== h.ver || g.editar !== h.editar) n++;
    }
    b.modulos.forEach((m) => {
      if (base.modulos.has(m.id) !== edicion.modulos.has(m.id)) n++;
    });
    return n;
  }, [edicion, base]);

  const desviosTotales = useMemo(
    () => bloques.reduce((acc, b) => acc + desvioBloque(b), 0),
    [bloques, desvioBloque]
  );

  const sucio = desviosTotales > 0 || Boolean(
    !esSistema && edicion && rol && (
      edicion.nombre !== rol.nombre || edicion.nivel !== rol.nivel || (edicion.descripcion || "") !== (rol.descripcion || "")
    )
  );

  // Por qué una casilla no se puede tocar. Fuera de estos dos casos, todas las
  // acciones se pueden marcar o desmarcar en cualquier rol, sea del sistema o no.
  const motivoBloqueo = useCallback(
    (i) => {
      if (!editable) return "Tu rango no alcanza para modificar este rol";
      if (SOLO_SISTEMA.has(i.clave)) {
        return ROLES_GESTION.includes(rol?.rol)
          ? "La gestión de roles siempre queda en Admin y Jefe de Preceptores"
          : "La gestión de roles solo la tienen el Admin y el Jefe de Preceptores";
      }
      if (rol?.rol === "admin" && pisoAdmin.has(i.clave)) {
        return "El Admin no puede quitarse esto: es lo único que lo deja entrar a Usuarios y reponer un acceso.";
      }
      return null;
    },
    [editable, rol, pisoAdmin]
  );

  const marcar = (clave, valor) => {
    setEdicion((e) => {
      if (!e) return e;
      const activos = new Set(e.activos);
      if (valor) activos.add(clave);
      else activos.delete(clave);
      return { ...e, activos };
    });
  };

  // "Marcar/Quitar todos" solo toca los items que el rol puede tener.
  const marcarGrupo = (grupo, valor) => {
    grupo.items.filter((i) => !motivoBloqueo(i)).forEach((i) => marcar(i.clave, valor));
  };

  // Alcance de un área. Ver y modificar van atados: nadie puede modificar lo que
  // no ve, así que "modifica" nunca queda por encima de "ve" (es lo que aplica el
  // backend en Alcances::recortar; la pantalla no deja armar lo contrario).
  const marcarAlcance = (area, campo, valor) => {
    setEdicion((e) => {
      if (!e) return e;
      const regla = { ...(e.alcance[area] || { ver: "ninguno", editar: "ninguno" }), [campo]: valor };
      if (pesoAlcance[regla.editar] > pesoAlcance[regla.ver]) regla.editar = regla.ver;
      return { ...e, alcance: { ...e.alcance, [area]: regla } };
    });
  };

  const marcarModulo = (id, valor) => {
    setEdicion((e) => {
      if (!e) return e;
      const modulos = new Set(e.modulos);
      if (valor) modulos.add(id);
      else modulos.delete(id);
      return { ...e, modulos };
    });
  };

  const restaurarModulos = () => {
    setEdicion((e) => (e ? { ...e, modulos: new Set(modulosBase(rol)) } : e));
  };

  const alternarBloque = (id) => {
    setCerrados((c) => {
      const siguiente = new Set(c);
      if (siguiente.has(id)) siguiente.delete(id);
      else siguiente.add(id);
      return siguiente;
    });
  };

  const todoContraido = cerrados.size >= bloques.length && bloques.length > 0;
  const alternarTodos = () => {
    setCerrados(todoContraido ? new Set() : new Set(bloques.map((b) => b.id)));
  };

  const guardar = async () => {
    if (!rol || !edicion) return;
    setGuardando(true);
    try {
      // La lista marcada es la lista del rol, igual que en un personalizado:
      // el backend ya no calcula quitas sobre el rango.
      const cuerpo = { permitidos: [...edicion.activos], alcance: edicion.alcance, modulos: [...edicion.modulos] };
      if (!esSistema) {
        Object.assign(cuerpo, {
          nombre: edicion.nombre,
          nivel: Number(edicion.nivel),
          descripcion: edicion.descripcion || null,
        });
      }
      await apiEditarRol(rol.rol, cuerpo);
      await cargarRoleDetalle().then((d) => setRoleDetalle(d));
      await cargar();
      showToast("Permisos del rol actualizados", "success");
    } catch (e) {
      mostrarAviso({ icon: "error", title: "No se pudo guardar", text: mensajeAmable(e, "Revisá los permisos marcados.") });
    } finally {
      setGuardando(false);
    }
  };

  // Volver a la lista de acciones que le corresponde al rango del rol.
  const restaurarPermisos = () => {
    setEdicion((e) => (e ? { ...e, activos: new Set(rol?.base_rango ?? []) } : e));
  };

  const restaurar = () => {
    if (!rol) return;
    setEdicion((e) => (e ? {
      ...e,
      activos: new Set(rol.efectivos ?? rol.permitidos ?? []),
      alcance: clonarAlcance(rol.alcance),
      modulos: new Set(modulosBase(rol)),
    } : e));
  };

  const crear = async () => {
    const nombre = nuevo.nombre.trim();
    if (!nombre) {
      mostrarAviso({ icon: "error", title: "Falta el nombre del rol" });
      return;
    }
    setGuardando(true);
    try {
      const res = await apiCrearRol({
        nombre,
        nivel: Number(nuevo.nivel),
        // Con "desde" el rol nuevo copia permisos, alcance y menú de un rol que ya
        // existe; la plantilla queda descartada.
        plantilla: nuevo.desde ? null : (nuevo.plantilla || null),
        desde_rol: nuevo.desde || null,
        descripcion: nuevo.descripcion.trim() || null,
      });
      setNuevoAbierto(false);
      setNuevo({ nombre: "", nivel: 1, plantilla: "profesor", desde: "", descripcion: "" });
      await cargarRoleDetalle().then((d) => setRoleDetalle(d));
      await cargar(false);
      if (res?.rol) setSelRol(res.rol);
      showToast(`Rol "${nombre}" creado`, "success");
    } catch (e) {
      mostrarAviso({ icon: "error", title: "No se pudo crear el rol", text: mensajeAmable(e, "Probá con otro nombre.") });
    } finally {
      setGuardando(false);
    }
  };

  const eliminar = async () => {
    if (!rol || esSistema) return;
    const detalles = await confirmAction(
      `¿Eliminar el rol "${rol.nombre}"? ${rol.usuarios > 0 ? `Todavía hay ${rol.usuarios} cuenta(s) con este rol.` : "No va a quedar constancia en las cuentas."}`,
      () => apiEliminarRol(rol.rol)
    );
    if (detalles === null) return;
    try {
      await cargarRoleDetalle().then((d) => setRoleDetalle(d));
      setSelRol(null);
      await cargar(false);
      showToast("Rol eliminado", "success");
    } catch (e) {
      mostrarAviso({ icon: "error", title: "No se pudo eliminar", text: mensajeAmable(e) });
    }
  };

  if (cargando && !dato) {
    return <div className="rp-loading">Cargando roles y permisos…</div>;
  }

  if (error) {
    return (
      <div className="rp-error">
        <p>{error}</p>
        <button className="rp-btn" onClick={() => cargar(false)}>Reintentar</button>
      </div>
    );
  }

  // Se cuenta sobre lo que el rol puede tener: las casillas que no se muestran
  // (respaldos fuera del Admin, gestión de roles en un personalizado) tampoco
  // entran en el total, si no el chip siempre marcaría una diferencia falsa.
  const cantidad = (r) => (r.efectivos ?? r.permitidos ?? []).filter((c) => !ocultasDe(r).has(c)).length;
  const totalDe = (r) => totalCatalogo - ocultasDe(r).size;
  const busqueda = buscador.trim().toLowerCase();
  // La búsqueda deja a la vista solo los módulos que tienen alguna acción nombrada.
  const bloquesFiltrados = busqueda
    ? bloques
        .map((b) => ({
          ...b,
          items: b.items.filter((i) => `${i.label} ${i.desc} ${i.clave}`.toLowerCase().includes(busqueda)),
        }))
        .filter((b) => b.items.length > 0)
    : bloques;

  // Resumen en texto plano: acciones habilitadas, qué ve de cada módulo y el menú.
  const resumenLineas = (() => {
    if (!rol || !edicion) return [];
    const out = [`${edicion.activos.size} de ${todas.length} acciones habilitadas.`];
    const visibles = bloques.filter((b) => {
      const r = b.area && edicion.alcance[b.area];
      return r && !(r.ver === "ninguno" && r.editar === "ninguno");
    });
    const datos = visibles.map((b) => {
      const r = edicion.alcance[b.area];
      const mismo = r.ver === r.editar
        ? `${etiquetasAlcance[r.ver] || r.ver}`
        : `ve ${etiquetasAlcance[r.ver] || r.ver} / modifica ${etiquetasAlcance[r.editar] || r.editar}`;
      return `${b.nombre}: ${mismo}`;
    });
    out.push(datos.length ? `Datos — ${datos.join(" · ")}.` : "Datos: no alcanza ningún módulo.");
    const visib = Object.keys(modulosCatalogo).filter((m) => edicion.modulos.has(m));
    out.push(`Menú: ${visib.length ? visib.map((m) => modulosCatalogo[m]).join(", ") : "sin módulos visibles"}.`);
    return out;
  })();

  return (
    <div className="rp-wrap">
      <div className="rp-head">
        <div>
          <h2 className="rp-title">Roles y permisos</h2>
          <p className="rp-sub">
            Elegí un rol y configurá módulo por módulo: si lo ve en el menú, qué puede ver adentro,
            qué puede modificar y qué acciones tiene. Todos los roles se editan igual: el rango define
            con qué permisos nace cada rol y qué pantalla le corresponde, pero la lista marcada es la
            que manda. Los personalizados se arman desde cero, con una plantilla o copiando a un rol existente.
          </p>
        </div>
        <button
          className="rp-btn rp-btn-primary"
          onClick={() => {
            setNuevo((x) => ({ ...x, desde: rol?.rol || "" }));
            setNuevoAbierto(true);
          }}
          title="Crear un rol nuevo, vacío, con una plantilla o copiando a un rol existente"
        >
          <Plus size={16} /> Nuevo rol
        </button>
      </div>

      <div className="rp-grid">
        {/* Columna de roles */}
        <aside className="rp-list">
          <div className="rp-list-title">Roles del sistema</div>
          {(dato?.roles || []).filter((r) => r.tipo === "sistema").map((r) => (
            <RolFicha
              key={r.rol}
              rol={r}
              activo={r.rol === selRol}
              usados={cantidad(r)}
              total={totalDe(r)}
              areas={areasConDatos}
              onClick={() => setSelRol(r.rol)}
            />
          ))}

          <div className="rp-list-title rp-list-title-extra">Roles personalizados</div>
          {(dato?.roles || []).filter((r) => r.tipo === "personalizado").length === 0 && (
            <p className="rp-vacio">Todavía no creaste ninguno. Usá "Nuevo rol" o una plantilla de Profesor o Preceptores.</p>
          )}
          {(dato?.roles || []).filter((r) => r.tipo === "personalizado").map((r) => (
            <RolFicha
              key={r.rol}
              rol={r}
              activo={r.rol === selRol}
              usados={cantidad(r)}
              total={totalDe(r)}
              areas={areasConDatos}
              onClick={() => setSelRol(r.rol)}
            />
          ))}
        </aside>

        {/* Panel del rol seleccionado */}
        <section className="rp-panel">
          {!rol || !edicion ? (
            <div className="rp-panel-vacio">
              <ShieldCheck size={30} />
              <p>Elegí un rol de la izquierda para ver y marcar sus permisos.</p>
            </div>
          ) : (
            <>
              <header className="rp-panel-head">
                <div className="rp-panel-campo rp-panel-campo-grande">
                  <label htmlFor="rp-nombre">Nombre del rol</label>
                  <input
                    id="rp-nombre"
                    className="rp-input"
                    value={edicion.nombre}
                    disabled={esSistema || !editable}
                    onChange={(e) => setEdicion((x) => ({ ...x, nombre: e.target.value }))}
                  />
                </div>
                <div className="rp-panel-campo">
                  <label htmlFor="rp-nivel">Rango</label>
                  <select
                    id="rp-nivel"
                    className="rp-input"
                    value={edicion.nivel}
                    disabled={esSistema || !editable}
                    onChange={(e) => setEdicion((x) => ({ ...x, nivel: Number(e.target.value) }))}
                  >
                    {nivelesPosibles.map((n) => (
                      <option key={n} value={n}>{`${n} · ${NIVEL_LABEL[n]}`}</option>
                    ))}
                  </select>
                </div>
                <div className="rp-panel-acciones">
                  {sucio && editable && (
                    <button className="rp-btn rp-btn-flat" onClick={restaurar} title="Volver a lo que este rol tiene guardado">
                      <RotateCcw size={15} /> Descartar
                    </button>
                  )}
                  <button className="rp-btn rp-btn-primary" onClick={guardar} disabled={!sucio || guardando || !editable}>
                    <Save size={15} /> Guardar
                  </button>
                  {!esSistema && editable && (
                    <button className="rp-btn rp-btn-danger" onClick={eliminar} title="Eliminar este rol">
                      <Trash2 size={15} />
                    </button>
                  )}
                </div>
              </header>

              <div className="rp-panel-meta">
                <span className={`rp-tag ${esSistema ? "rp-tag-sistema" : "rp-tag-nuevo"}`}>
                  {esSistema ? "Rol del sistema" : "Rol personalizado"}
                </span>
                <span className="rp-meta-dato"><Users size={13} /> {rol.usuarios} cuenta(s)</span>
                <span className="rp-meta-dato">{cantidad(rol)} de {todas.length} permisos</span>
                {esSistema && rol.a_medida && (
                  <span className="rp-meta-dato" title="Alguien le cambió la lista de permisos: no es exactamente la que da el rango">
                    <PencilLine size={13} /> ajustado a mano
                  </span>
                )}
                {!editable && (
                  <span className="rp-meta-aviso">
                    <Lock size={12} /> Este rol está en tu rango o arriba: solo lo modifica un rango superior.
                  </span>
                )}
                {!esSistema && (
                  <input
                    className="rp-input rp-input-desc"
                    placeholder="Para qué sirve este rol (opcional)"
                    value={edicion.descripcion}
                    disabled={!editable}
                    onChange={(e) => setEdicion((x) => ({ ...x, descripcion: e.target.value }))}
                  />
                )}
              </div>

              <div className="rp-resumen">
                {resumenLineas.map((l, i) => (
                  <p className="rp-resumen-linea" key={i}>{l}</p>
                ))}
              </div>

              <div className="rp-barra">
                <span className="rp-meta-dato"><Check size={13} /> {edicion.activos.size} de {todas.length} acciones habilitadas</span>
                <span className="rp-meta-dato"><Layers size={13} /> {edicion.modulos.size} de {totalModulos} módulos visibles en el menú</span>
                {desviosTotales > 0 && (
                  <span className="rp-cambio" title="Cambios sin guardar en este rol">{desviosTotales} cambio(s) sin guardar</span>
                )}
                <span className="rp-barra-botones">
                  <button className="rp-btn rp-btn-flat" onClick={alternarTodos} title="Mostrar u ocultar el detalle de cada módulo">
                    {todoContraido ? "Expandir todo" : "Contraer todo"}
                  </button>
                  <button className="rp-btn rp-btn-flat" onClick={restaurarPermisos} disabled={!editable} title="Marcar solo las acciones que le corresponden al rango del rol">
                    <RotateCcw size={14} /> Permisos según el rango
                  </button>
                  <button className="rp-btn rp-btn-flat" onClick={restaurarModulos} disabled={!editable} title="Volver a la lista de módulos que le corresponde al rango">
                    <RotateCcw size={14} /> Menú según el rango
                  </button>
                </span>
              </div>

              <p className="rp-ayuda">
                Cada bloque es un módulo del sistema. En el encabezado se marca <b>si aparece en el menú</b>:
                ocultarlo también cierra la pantalla, aunque se escriba la dirección. Las dos filas de alcance
                responden <b>sobre qué datos</b> — "Todos" son todos los cursos, "Asignados" son los años y
                divisiones cargados en la cuenta (Usuarios → Asignaciones) y "Nada" esconde el módulo — y
                "modificar" nunca puede ampliar lo que se ve. Las <b>acciones</b> mandan sobre todo: ningún
                alcance ni módulo visible habilita una acción que el rol no tiene.
              </p>

              <div className="rp-buscar">
                <Search size={15} />
                <input
                  className="rp-input"
                  placeholder="Buscar una acción: notas, asistencia, exportar, subir archivos…"
                  value={buscador}
                  onChange={(e) => setBuscador(e.target.value)}
                  aria-label="Buscar acciones"
                />
                {buscador && (
                  <button className="rp-enlace" onClick={() => setBuscador("")}>Limpiar</button>
                )}
              </div>

              {bloquesFiltrados.length === 0 && (
                <p className="rp-vacio">Ninguna acción del sistema coincide con "{buscador}".</p>
              )}

              <div className="rp-bloques">
                {bloquesFiltrados.map((b) => {
                  // Con búsqueda el bloque se abre solo: si no, el resultado quedaría oculto.
                  const abierto = busqueda ? true : !cerrados.has(b.id);
                  const marcables = b.items.filter((i) => !motivoBloqueo(i));
                  const todoMarcado = marcables.length > 0 && marcables.every((i) => edicion.activos.has(i.clave));
                  const ocultoTodo = b.modulos.length > 0 && b.modulos.every((m) => !edicion.modulos.has(m.id));
                  const regla = b.area ? (edicion.alcance[b.area] || { ver: "ninguno", editar: "ninguno" }) : null;
                  const desvio = desvioBloque(b);
                  return (
                    <article className={`rp-bloque ${desvio > 0 ? "rp-bloque-tocada" : ""}`} key={b.id}>
                      <header className="rp-bloque-head">
                        <button
                          type="button"
                          className="rp-bloque-titulo"
                          onClick={() => alternarBloque(b.id)}
                          aria-expanded={abierto}
                          title={abierto ? "Ocultar el detalle de este módulo" : "Ver el detalle de este módulo"}
                        >
                          {abierto ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
                          <span className="rp-bloque-nombre">{b.nombre}</span>
                          <span className="rp-bloque-estado" title="Acciones habilitadas en este módulo">
                            <Check size={12} /> {b.items.filter((i) => edicion.activos.has(i.clave)).length}/{b.items.length}
                          </span>
                          {regla && (
                            <span
                              className="rp-bloque-alcance"
                              title={`Ve ${etiquetasAlcance[regla.ver] || regla.ver} · modifica ${etiquetasAlcance[regla.editar] || regla.editar}`}
                            >
                              <Eye size={12} /> {etiquetasAlcance[regla.ver] || regla.ver}
                              <PencilLine size={12} /> {etiquetasAlcance[regla.editar] || regla.editar}
                            </span>
                          )}
                          {desvio > 0 && <span className="rp-cambio" title="Cambios sin guardar en este módulo">{desvio}</span>}
                        </button>

                        <div className="rp-bloque-menu">
                          {b.modulos.length === 0 ? (
                            <span
                              className="rp-bloque-sinmenu"
                              title="No es un módulo del menú: son acciones que se usan adentro de otras pantallas"
                            >
                              Acciones transversales
                            </span>
                          ) : b.modulos.map((m) => {
                            const activo = edicion.modulos.has(m.id);
                            return (
                              <button
                                type="button"
                                key={m.id}
                                className={`rp-chip ${activo ? "rp-chip-on" : ""}`}
                                disabled={!editable}
                                onClick={() => marcarModulo(m.id, !activo)}
                                title={activo
                                  ? "Se ve en el menú: tocá para ocultarlo (la pantalla queda cerrada)"
                                  : "Oculto en el menú: tocá para mostrarlo"}
                              >
                                {activo ? <Check size={13} /> : <X size={13} />} {m.nombre}
                              </button>
                            );
                          })}
                        </div>
                      </header>

                      {abierto && (
                        <div className="rp-bloque-cuerpo">
                          {regla ? (
                            <div className="rp-alcan">
                              <span className="rp-alcan-label"><Eye size={13} /> Qué puede ver</span>
                              <div className="rp-alcan-opciones">
                                {b.opciones.map((v) => (
                                  <AlcanceBoton
                                    key={v}
                                    valor={v}
                                    etiqueta={etiquetasAlcance[v] || ALCANCE_ETIQUETA[v] || v}
                                    activo={regla.ver === v}
                                    disabled={!editable}
                                    onClick={() => marcarAlcance(b.area, "ver", v)}
                                  />
                                ))}
                              </div>
                              <span className="rp-alcan-ayuda">{b.area_ver}</span>

                              <span className="rp-alcan-label"><PencilLine size={13} /> Qué puede modificar</span>
                              <div className="rp-alcan-opciones">
                                {b.opciones.map((v) => (
                                  <AlcanceBoton
                                    key={v}
                                    valor={v}
                                    etiqueta={etiquetasAlcance[v] || ALCANCE_ETIQUETA[v] || v}
                                    activo={regla.editar === v}
                                    disabled={!editable || pesoAlcance[v] > pesoAlcance[regla.ver]}
                                    motivo={pesoAlcance[v] > pesoAlcance[regla.ver]
                                      ? 'No se puede modificar más de lo que se ve: ampliá primero "Ver".'
                                      : null}
                                    onClick={() => marcarAlcance(b.area, "editar", v)}
                                  />
                                ))}
                              </div>
                              <span className="rp-alcan-ayuda">{b.area_editar}</span>
                            </div>
                          ) : (
                            <p className="rp-alcan-sindatos">
                              Estas acciones no cuelgan de un curso: valen igual para todos los datos del sistema.
                            </p>
                          )}

                          {ocultoTodo && (
                            <p className="rp-nota-menu">
                              El módulo está oculto en el menú: nadie con este rol lo abre desde la barra lateral
                              ni escribiendo la dirección. El alcance y las acciones se guardan igual, para cuando
                              se vuelva a mostrar.
                            </p>
                          )}

                          <div className="rp-bloque-acciones-head">
                            <span className="rp-alcan-label">Qué puede hacer en este módulo</span>
                            <button
                              className="rp-enlace"
                              onClick={() => marcarGrupo(b, !todoMarcado)}
                              disabled={marcables.length === 0 || !editable}
                            >
                              {todoMarcado ? "Quitar todas" : "Marcar todas"}
                            </button>
                          </div>

                          <ul className="rp-items">
                            {b.items.map((i) => {
                              const activo = edicion.activos.has(i.clave);
                              const bloqueo = motivoBloqueo(i);
                              return (
                                <li className={`rp-item ${activo ? "rp-item-on" : ""} ${bloqueo ? "rp-item-fuera" : ""}`} key={i.clave}>
                                  <label className="rp-item-texto">
                                    <input
                                      type="checkbox"
                                      checked={activo}
                                      disabled={Boolean(bloqueo)}
                                      onChange={(e) => marcar(i.clave, e.target.checked)}
                                    />
                                    <span>
                                      <span className="rp-item-label">{i.label}</span>
                                      <span className="rp-item-desc">{i.desc}</span>
                                    </span>
                                  </label>
                                  <span className="rp-item-nivel" title={bloqueo || `Suele entrar con el rango ${i.nivel}; en cualquier rol se puede marcar o desmarcar`}>
                                    {bloqueo ? <Lock size={12} /> : activo ? <Check size={13} /> : <X size={13} />}
                                    <b>{i.nivel}</b>
                                  </span>
                                </li>
                              );
                            })}
                          </ul>
                        </div>
                      )}
                    </article>
                  );
                })}
              </div>
            </>
          )}
        </section>
      </div>

      {/* Modal: nuevo rol */}
      {montadoNuevo && (
        <div className={`rp-overlay sys-capa${cerrandoNuevo ? " sys-cerrando" : ""}`}>
          <div className={`rp-modal sys-dialogo${cerrandoNuevo ? " sys-cerrando" : ""}`} role="dialog" aria-modal="true">
              <h3>Nuevo rol</h3>
              <p className="rp-modal-sub">
                Puede arrancar vacío, con una plantilla o copiando exactamente lo que hace un rol
                existente (permisos, alcance y menú). Después lo ajustás en la lista.
              </p>

              <label className="rp-campo" htmlFor="rp-n-nombre">Nombre</label>
              <input
                id="rp-n-nombre"
                className="rp-input"
                placeholder="Ej: Auxiliar de secretaría"
                value={nuevo.nombre}
                onChange={(e) => setNuevo((x) => ({ ...x, nombre: e.target.value }))}
              />

              <label className="rp-campo" htmlFor="rp-n-desde">Copiar de un rol existente</label>
              <select
                id="rp-n-desde"
                className="rp-input"
                value={nuevo.desde}
                onChange={(e) => {
                  const origen = (dato?.roles || []).find((r) => r.rol === e.target.value);
                  setNuevo((x) => ({
                    ...x,
                    desde: e.target.value,
                    plantilla: e.target.value ? "" : x.plantilla,
                    nivel: origen ? origen.nivel : x.nivel,
                  }));
                }}
              >
                <option value="">Sin copiar (usar plantilla)</option>
                {(dato?.roles || []).map((r) => (
                  <option key={r.rol} value={r.rol}>{`${rolLabel(r.rol)} · rango ${r.nivel}`}</option>
                ))}
              </select>

              {!nuevo.desde && (
                <>
                  <label className="rp-campo" htmlFor="rp-n-plantilla">Plantilla</label>
                  <select
                    id="rp-n-plantilla"
                    className="rp-input"
                    value={nuevo.plantilla}
                    onChange={(e) => {
                      const p = (dato?.plantillas || []).find((x) => x.id === e.target.value);
                      setNuevo((x) => ({ ...x, plantilla: e.target.value, nivel: p ? p.nivel : x.nivel }));
                    }}
                  >
                    <option value="">Sin plantilla (empezar vacío)</option>
                    {(dato?.plantillas || []).map((p) => (
                      <option key={p.id} value={p.id}>Plantilla · {p.nombre}</option>
                    ))}
                  </select>
                </>
              )}

              <label className="rp-campo" htmlFor="rp-n-nivel">Rango</label>
              <select
                id="rp-n-nivel"
                className="rp-input"
                value={nuevo.nivel}
                onChange={(e) => setNuevo((x) => ({ ...x, nivel: Number(e.target.value) }))}
              >
                {[1, 2, 3, 4, 5]
                  .filter((n) => n <= Number(dato?.mi_nivel || 0))
                  .map((n) => (
                    <option key={n} value={n}>{`${n} · ${NIVEL_LABEL[n]}`}</option>
                  ))}
              </select>

              <label className="rp-campo" htmlFor="rp-n-desc">Descripción</label>
              <input
                id="rp-n-desc"
                className="rp-input"
                placeholder="Qué hace esta persona en la escuela"
                value={nuevo.descripcion}
                onChange={(e) => setNuevo((x) => ({ ...x, descripcion: e.target.value }))}
              />

              {nuevo.plantilla && !nuevo.desde && (
                <p className="rp-modal-ayuda">
                  {(dato?.plantillas || []).find((p) => p.id === nuevo.plantilla)?.descripcion}
                </p>
              )}

              <div className="rp-modal-botones">
                <button className="rp-btn" onClick={() => setNuevoAbierto(false)}>Cancelar</button>
                <button className="rp-btn rp-btn-primary" onClick={crear} disabled={guardando}>
                  <Plus size={15} /> Crear rol
                </button>
              </div>
          </div>
        </div>
      )}

      {confirmModal}
      {avisoModal}
    </div>
  );
}

// Botón de una opción de alcance dentro del bloque del módulo.
function AlcanceBoton({ valor, etiqueta, activo, disabled, motivo, onClick }) {
  const titulos = {
    todos: "Todas las filas del sistema",
    asignados: "Solo los cursos asignados a la cuenta",
    ninguno: "Nada: el módulo queda oculto",
  };
  return (
    <button
      type="button"
      className={`rp-opcion ${activo ? "rp-opcion-on" : ""}`}
      disabled={disabled}
      title={motivo || titulos[valor]}
      onClick={onClick}
    >
      {etiqueta}
    </button>
  );
}

// Tarjeta de un rol en la columna izquierda. `usados` y `total` ya descuentan
// las casillas que ese rol no puede tener, para que la fracción nunca pase de 1.
function RolFicha({ rol, activo, usados, total, areas, onClick }) {
  const areasVisibles = Object.values(rol.alcance || {}).filter((a) => a.ver !== "ninguno").length;
  return (
    <button className={`rp-rol ${activo ? "rp-rol-activo" : ""}`} onClick={onClick}>
      <span className="rp-rol-nombre">
        {rolLabel(rol.rol)}
      </span>
      <span className="rp-rol-meta">
        <span className="rp-rol-nivel">rango {rol.nivel}</span>
        <span className="rp-rol-usuarios"><Users size={11} /> {rol.usuarios}</span>
        <span className="rp-rol-permisos">{usados}/{total}</span>
        {rol.tipo === "sistema" && rol.a_medida && (
          <span className="rp-rol-medida" title="Su lista de permisos no es exactamente la de su rango: alguien la ajustó acá">
            a medida
          </span>
        )}
        {areas > 0 && (
          <span className="rp-rol-alcance" title="Áreas de datos que puede ver">
            <Layers size={11} /> {areasVisibles}/{areas}
          </span>
        )}
        {rol.editable === false && <span className="rp-rol-lock" title="Tu rango no llega para modificar este rol"><Lock size={11} /></span>}
      </span>
    </button>
  );
}
