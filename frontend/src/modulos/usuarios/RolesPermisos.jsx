// Pestaña "Roles y permisos" del módulo Usuarios.
// Muestra el catálogo de todas las acciones del sistema y deja marcar qué puede
// hacer cada rol, sobre qué datos y qué ve en el menú lateral.
// Solo entra el administrador y el jefe de preceptores.
//
// Reglas que refleja la pantalla (las mismas del backend):
//  - Rol de sistema: su base es el rango (nivel), así que solo se le QUITAN permisos.
//  - Rol personalizado: se arma desde cero, con una plantilla o copiando a un rol existente.
//  - Permiso y alcance son dos filtros en serie: primero la acción, después los datos.
//    Un alcance nunca habilita una acción que el permiso bloquea, solo la restringe.
import React, { useEffect, useMemo, useState, useCallback } from "react";
import useCierreAnimado from "../../compartido/lib/useCierreAnimado";
import { ShieldCheck, Plus, Save, Trash2, RotateCcw, Users, Lock, Check, X, Search, ListChecks, Layers, PanelsTopLeft } from "lucide-react";
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

// Las tres preguntas que se le responden a un rol: acción, datos y menú.
const SECCIONES = [
  { id: "acciones", label: "Qué puede hacer", Icon: ListChecks },
  { id: "datos", label: "Sobre qué datos", Icon: Layers },
  { id: "menu", label: "Qué ve en el menú", Icon: PanelsTopLeft },
];

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
const firmaModulos = (lista) => [...lista].sort().join(",");

export default function RolesPermisos() {
  const [confirmAction, confirmModal] = useConfirmAction();
  const [mostrarAviso, , avisoModal] = useAviso();
  const showToast = useToast();

  const [dato, setDato] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState("");
  const [selRol, setSelRol] = useState(null);
  const [edicion, setEdicion] = useState(null);
  const [seccion, setSeccion] = useState("acciones");
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

  // Lista completa de claves del catálogo: es el total sobre el que se cuenta.
  const todas = useMemo(
    () => (dato?.grupos || []).flatMap((g) => g.items.map((i) => i.clave)),
    [dato]
  );
  const etiquetasAlcance = dato?.etiquetas_alcance || ALCANCE_ETIQUETA;
  const areas = dato?.areas || [];
  const modulosCatalogo = dato?.modulos || {};

  // El estado editable arranca con lo que el rol tiene hoy.
  useEffect(() => {
    if (!rol) {
      setEdicion(null);
      return;
    }
    const activos = rol.efectivos ?? rol.permitidos ?? [];
    setEdicion({
      nombre: rol.nombre,
      nivel: rol.nivel,
      descripcion: rol.descripcion || "",
      activos: new Set(activos),
      alcance: clonarAlcance(rol.alcance),
      modulos: new Set(modulosBase(rol)),
    });
    setSeccion("acciones");
  }, [rol?.rol, rol?.nivel]); // eslint-disable-line react-hooks/exhaustive-deps

  // Qué se salió de lo guardado, en cada una de las tres dimensiones.
  const desvios = useMemo(() => {
    if (!rol || !edicion) return { permisos: 0, datos: 0, menu: 0 };
    const actuales = new Set(rol.efectivos ?? rol.permitidos ?? []);
    let permisos = 0;
    actuales.forEach((c) => { if (!edicion.activos.has(c)) permisos++; });
    edicion.activos.forEach((c) => { if (!actuales.has(c)) permisos++; });

    const baseAlcance = rol.alcance || {};
    let datos = 0;
    Object.keys(baseAlcance).forEach((area) => {
      const b = baseAlcance[area];
      const h = edicion.alcance[area] || b;
      if (b.ver !== h.ver || b.editar !== h.editar) datos++;
    });

    const menu = firmaModulos(edicion.modulos) === firmaModulos(modulosBase(rol)) ? 0 : 1;
    return { permisos, datos, menu };
  }, [rol, edicion]);

  const sucio = desvios.permisos > 0 || desvios.datos > 0 || desvios.menu > 0 || Boolean(
    !esSistema && edicion && rol && (
      edicion.nombre !== rol.nombre || edicion.nivel !== rol.nivel || (edicion.descripcion || "") !== (rol.descripcion || "")
    )
  );

  // Por qué una casilla no se puede tocar.
  const motivoBloqueo = useCallback(
    (i) => {
      if (!editable) return "Tu rango no alcanza para modificar este rol";
      if (esSistema && i.nivel > (rol?.nivel ?? 0)) return `Fuera del rango de este rol: pide rango ${i.nivel}`;
      if (SOLO_SISTEMA.has(i.clave)) {
        return ROLES_GESTION.includes(rol?.rol)
          ? "La gestión de roles siempre queda en Admin y Jefe de Preceptores"
          : "La gestión de roles solo la tienen el Admin y el Jefe de Preceptores";
      }
      return null;
    },
    [editable, esSistema, rol]
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

  const guardar = async () => {
    if (!rol || !edicion) return;
    setGuardando(true);
    try {
      const comun = { alcance: edicion.alcance, modulos: [...edicion.modulos] };
      if (esSistema) {
        // La quita solo se calcula sobre la base del rol: lo que nunca tuvo por su
        // rango no es una quita, y registrarlas cambiaría el rol a modo filtro.
        const base = rol.permitidos || [];
        const negados = base.filter((c) => !edicion.activos.has(c));
        await apiEditarRol(rol.rol, { negados, ...comun });
      } else {
        await apiEditarRol(rol.rol, {
          nombre: edicion.nombre,
          nivel: Number(edicion.nivel),
          descripcion: edicion.descripcion || null,
          permitidos: [...edicion.activos],
          ...comun,
        });
      }
      await cargarRoleDetalle().then((d) => setRoleDetalle(d));
      await cargar();
      showToast("Permisos del rol actualizados", "success");
    } catch (e) {
      mostrarAviso({ icon: "error", title: "No se pudo guardar", text: mensajeAmable(e, "Revisá los permisos marcados.") });
    } finally {
      setGuardando(false);
    }
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

  const cantidad = (r) => (r.efectivos ?? r.permitidos ?? []).length;
  const busqueda = buscador.trim().toLowerCase();
  const gruposFiltrados = (dato?.grupos || [])
    .map((g) => ({
      ...g,
      items: g.items.filter((i) => !busqueda || `${i.label} ${i.desc} ${i.clave}`.toLowerCase().includes(busqueda)),
    }))
    .filter((g) => g.items.length > 0);

  // Resumen en texto plano: qué puede hacer, sobre qué datos y qué ve en el menú.
  const resumenLineas = (() => {
    if (!rol || !edicion) return [];
    const out = [`${edicion.activos.size} de ${todas.length} acciones habilitadas.`];
    const visibles = areas.filter((a) => {
      const r = edicion.alcance[a.area];
      return r && !(r.ver === "ninguno" && r.editar === "ninguno");
    });
    const datos = visibles.map((a) => {
      const r = edicion.alcance[a.area];
      const mismo = r.ver === r.editar
        ? `${etiquetasAlcance[r.ver] || r.ver}`
        : `ve ${etiquetasAlcance[r.ver] || r.ver} / modifica ${etiquetasAlcance[r.editar] || r.editar}`;
      return `${a.nombre}: ${mismo}`;
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
            Elegí un rol y marcá qué puede hacer, sobre qué datos y qué ve en el menú.
            Los roles del sistema entran por su rango: a esos solo se les quitan permisos.
            Los personalizados se arman desde cero, con una plantilla o copiando a un rol existente.
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
              total={todas.length}
              areas={areas.length}
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
              total={todas.length}
              areas={areas.length}
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
                {esSistema && (rol.negados || []).length > 0 && (
                  <span className="rp-meta-aviso">
                    {rol.negados.length} permiso(s) quitado(s) — el rango no alcanza para sumarle otros nuevos
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

              <div className="rp-tabs" role="tablist">
                {SECCIONES.map(({ id, label, Icon }) => {
                  const desvio = desvios[id === "acciones" ? "permisos" : id === "datos" ? "datos" : "menu"];
                  return (
                    <button
                      key={id}
                      role="tab"
                      aria-selected={seccion === id}
                      className={`rp-tab ${seccion === id ? "rp-tab-activo" : ""}`}
                      onClick={() => setSeccion(id)}
                    >
                      <Icon size={15} /> {label}
                      {desvio > 0 && <span className="rp-tab-punto" title="Cambios sin guardar en esta solapa">{desvio}</span>}
                    </button>
                  );
                })}
              </div>

              {seccion === "acciones" && (
                <>
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

                  {gruposFiltrados.length === 0 && (
                    <p className="rp-vacio">Ninguna acción del sistema coincide con "{buscador}".</p>
                  )}

                  <div className="rp-grupos">
                    {gruposFiltrados.map((g) => {
                      const marcables = g.items.filter((i) => !motivoBloqueo(i));
                      const todoMarcado = marcables.length > 0 && marcables.every((i) => edicion.activos.has(i.clave));
                      return (
                        <div className="rp-grupo" key={g.grupo}>
                          <div className="rp-grupo-head">
                            <h3>{g.grupo}</h3>
                            <button
                              className="rp-enlace"
                              onClick={() => marcarGrupo(g, !todoMarcado)}
                              disabled={marcables.length === 0 || !editable}
                            >
                              {todoMarcado ? "Quitar todos" : "Marcar todos"}
                            </button>
                          </div>
                          <ul className="rp-items">
                            {g.items.map((i) => {
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
                                  <span
                                    className="rp-item-nivel"
                                    title={bloqueo || `Rango mínimo habitual: ${i.nivel}`}
                                  >
                                    {bloqueo ? <Lock size={12} /> : activo ? <Check size={13} /> : <X size={13} />}
                                    <b>{i.nivel}</b>
                                  </span>
                                </li>
                              );
                            })}
                          </ul>
                        </div>
                      );
                    })}
                  </div>
                </>
              )}

              {seccion === "datos" && (
                <>
                  <p className="rp-ayuda">
                    El alcance responde <b>sobre qué datos</b>: "Todos" son todos los cursos del sistema,
                    "Asignados" son los años y divisiones cargados en la cuenta (módulo Usuarios → Asignaciones)
                    y "Nada" oculta el área. Se aplica encima de los permisos: ningún alcance habilita una acción bloqueada.
                    Además <b>"modificar" nunca puede ampliar a "ver"</b>: si un área solo muestra los cursos
                    asignados, no se puede editar ninguna otra.
                  </p>
                  <div className="rp-matriz">
                    <div className="rp-matriz-cab rp-matriz-cab-area">Área</div>
                    <div className="rp-matriz-cab">Ver</div>
                    <div className="rp-matriz-cab">Modificar</div>
                    {areas.map((a) => {
                      const regla = edicion.alcance[a.area] || { ver: "ninguno", editar: "ninguno" };
                      const guardado = rol.alcance?.[a.area] || { ver: "ninguno", editar: "ninguno" };
                      const tocada = guardado.ver !== regla.ver || guardado.editar !== regla.editar;
                      const sinCurso = !a.opciones.includes("asignados");
                      return (
                        <div className={`rp-matriz-fila ${tocada ? "rp-matriz-tocada" : ""}`} key={a.area}>
                          <div className="rp-matriz-area">
                            <span className="rp-matriz-nombre">{a.nombre}</span>
                            <span className="rp-matriz-ayuda">
                              {regla.ver === "ninguno" && regla.editar === "ninguno"
                                ? "Oculto para este rol"
                                : `ve ${a.ver} · modifica ${a.editar}`}
                            </span>
                          </div>
                          <div className="rp-matriz-opciones">
                            {a.opciones.map((v) => (
                              <AlcanceBoton
                                key={v}
                                valor={v}
                                etiqueta={etiquetasAlcance[v] || ALCANCE_ETIQUETA[v] || v}
                                activo={regla.ver === v}
                                disabled={!editable}
                                corto={v === "asignados" && sinCurso}
                                onClick={() => marcarAlcance(a.area, "ver", v)}
                              />
                            ))}
                          </div>
                          <div className="rp-matriz-opciones">
                            {a.opciones.map((v) => (
                              <AlcanceBoton
                                key={v}
                                valor={v}
                                etiqueta={etiquetasAlcance[v] || ALCANCE_ETIQUETA[v] || v}
                                activo={regla.editar === v}
                                disabled={!editable || pesoAlcance[v] > pesoAlcance[regla.ver]}
                                motivo={
                                  pesoAlcance[v] > pesoAlcance[regla.ver]
                                    ? 'No se puede modificar más de lo que se ve: ampliá primero "Ver".'
                                    : null
                                }
                                corto={v === "asignados" && sinCurso}
                                onClick={() => marcarAlcance(a.area, "editar", v)}
                              />
                            ))}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                  {!editable && <p className="rp-ayuda">Este rol no lo podés modificar: está en tu rango o arriba.</p>}
                </>
              )}

              {seccion === "menu" && (
                <>
                  <p className="rp-ayuda">
                    Los módulos marcados son los que aparecen en el menú lateral de las cuentas con este rol.{" "}
                    {rol.modulos === null
                      ? "Hoy usa la lista que le corresponde por rango; en cuanto cambies algo queda guardada a medida."
                      : "Este rol ya tiene una lista propia."}
                  </p>
                  <div className="rp-chips">
                    {Object.entries(modulosCatalogo).map(([id, nombre]) => {
                      const activo = edicion.modulos.has(id);
                      return (
                        <button
                          type="button"
                          key={id}
                          className={`rp-chip ${activo ? "rp-chip-on" : ""}`}
                          disabled={!editable}
                          onClick={() => marcarModulo(id, !activo)}
                          title={activo ? "Se ve en el menú: tocá para ocultarlo" : "Oculto en el menú: tocá para mostrarlo"}
                        >
                          {activo ? <Check size={13} /> : <X size={13} />} {nombre}
                        </button>
                      );
                    })}
                  </div>
                  <div className="rp-chips-acciones">
                    <button className="rp-btn rp-btn-flat" onClick={restaurarModulos} disabled={!editable}>
                      <RotateCcw size={14} /> Dejar la lista que corresponde al rango
                    </button>
                    <span className="rp-meta-dato">{edicion.modulos.size} de {Object.keys(modulosCatalogo).length} módulos visibles</span>
                  </div>
                </>
              )}
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

// Botón de una opción de alcance dentro de la matriz.
function AlcanceBoton({ valor, etiqueta, activo, disabled, corto, motivo, onClick }) {
  const titulos = {
    todos: "Todas las filas del sistema",
    asignados: "Solo los cursos asignados a la cuenta",
    ninguno: "Nada: el área queda oculta",
  };
  return (
    <button
      type="button"
      className={`rp-opcion ${activo ? "rp-opcion-on" : ""}`}
      disabled={disabled || corto}
      title={corto ? "Esta área no depende de un curso asignado" : motivo || titulos[valor]}
      onClick={onClick}
    >
      {etiqueta}
    </button>
  );
}

// Tarjeta de un rol en la columna izquierda.
function RolFicha({ rol, activo, total, areas, onClick }) {
  const usados = (rol.efectivos ?? rol.permitidos ?? []).length;
  const quitas = (rol.negados || []).length;
  const aMedida = rol.modulos !== null || quitas > 0;
  const areasVisibles = Object.values(rol.alcance || {}).filter((a) => a.ver !== "ninguno").length;
  return (
    <button className={`rp-rol ${activo ? "rp-rol-activo" : ""}`} onClick={onClick}>
      <span className="rp-rol-nombre">
        {rolLabel(rol.rol)}
        {aMedida && <i className="rp-punto" title="Tiene permisos quitados o configuración a medida" />}
      </span>
      <span className="rp-rol-meta">
        <span className="rp-rol-nivel">rango {rol.nivel}</span>
        <span className="rp-rol-usuarios"><Users size={11} /> {rol.usuarios}</span>
        <span className="rp-rol-permisos">{usados}/{total}</span>
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
