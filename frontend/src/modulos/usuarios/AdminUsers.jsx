// Pagina de administracion de usuarios del sistema.
// Permite ver, aprobar/rechazar, crear, eliminar usuarios y asignar cursos/divisiones.
import React, { useEffect, useState, useCallback, useRef } from "react";
import { UserCheck, UserX, Users, Clock, Shield, ShieldCheck, BookOpen, Save, Search, Plus, Trash2, RefreshCw, X, Eye, EyeOff, ArrowLeft, ChevronRight, KeyRound, Copy, Check } from "lucide-react";
import useCierreAnimado from "../../compartido/lib/useCierreAnimado";
import useAviso from "../../compartido/componentes/modals/useAviso";
import usePasswordPrompt from "../../compartido/componentes/modals/usePasswordPrompt";
import useAvisoDeCambios from "../../compartido/hooks/useAvisoDeCambios";
import {
  apiAdminUsers,
  apiAdminPendingUsers,
  apiAdminVerifyUser,
  apiAdminGetAssignments,
  apiAdminSaveAssignments,
  apiAdminCreateUser,
  apiAdminDeleteUser,
  apiAdminClaveTemporal,
  apiAdminUpdateUserRole,
  apiRoles,
} from "../../compartido/api/auth";
import { useAuth } from "../auth/AuthContext";
import RolesPermisos from "./RolesPermisos";
import { canManageRole, rolLabel, cargarRoleDetalle, ROLE_LEVEL } from "../auth/roles";
import Breadcrumbs from "../../compartido/componentes/Common/Breadcrumbs";
import EmptyState from "../../compartido/componentes/Common/EmptyState";
import Avatar from "../../compartido/componentes/Common/Avatar";
import { useToast } from "../../compartido/componentes/Common/Toast";
import { useDeshacerAviso } from "../../compartido/lib/deshacer.jsx";
import ExportButton from "../../compartido/componentes/Common/ExportButton";
import { getCached, setCache, clearCache } from "../../compartido/lib/pageCache";
import { apiGet } from "../../compartido/lib/api";
import useConfirmAction from "../../compartido/hooks/useConfirmAction";
import { cursoLabel } from "../../compartido/lib/cursos";
import { mensajeAmable } from "../../compartido/lib/errores";
import { formatearFecha } from "../../compartido/lib/fechas";
import "./AdminUsers.css";

// Etiquetas de roles: se toma de modulos/auth/roles para que los roles
// personalizados también tengan su nombre.

// Roles que NO se ofrecen al crear/editar una cuenta desde acá: son la gestión
// global del sistema y las cuentas pendientes de aprobar. Los roles personalizados
// ("Roles y permisos") quedan habilitados y canManageRole() filtra por rango.
const ROLES_NO_ASIGNABLES = ["admin", "directivo", "sin_rol"];
const esRolAsignable = (r) => !ROLES_NO_ASIGNABLES.includes(r);

// Roles que no necesitan cursos asignados (acceden a todo). Los personalizados y
// el resto de los roles se administran con asignaciones: son las que usa el
// alcance "Solo los asignados".
const ROLES_SIN_ASIGNACIONES = ["admin", "directivo", "jefe_preceptores"];
const esRolConAsignaciones = (r) => !ROLES_SIN_ASIGNACIONES.includes(r);

// Opciones de un selector de rol, agrupadas para que se distinga qué viene del
// sistema y qué creó la escuela en "Roles y permisos". Solo muestra los roles que
// el rango de quien está logueado puede dar (canManageRole), más el rol actual del
// usuario editado si no estuviera en esa lista.
function OpcionesRol({ roles, actor, incluir, soloAsignables }) {
  const validos = roles.filter((r) => canManageRole(actor, r) && (!soloAsignables || esRolAsignable(r)));
  if (incluir && !validos.includes(incluir)) validos.unshift(incluir);
  const sistema = validos.filter((r) => ROLE_LEVEL[r] !== undefined);
  const propios = validos.filter((r) => ROLE_LEVEL[r] === undefined);
  return (
    <>
      {sistema.length > 0 && (
        <optgroup label="Roles del sistema">
          {sistema.map((r) => <option key={r} value={r}>{rolLabel(r)}</option>)}
        </optgroup>
      )}
      {propios.length > 0 && (
        <optgroup label="Roles personalizados">
          {propios.map((r) => <option key={r} value={r}>{rolLabel(r)}</option>)}
        </optgroup>
      )}
    </>
  );
}

// Etiquetas y estilos para los estados de los usuarios
const STATUS_LABELS = {
  pending: { label: "Pendiente", class: "admin-status-pending" },
  active: { label: "Activo", class: "admin-status-active" },
  rejected: { label: "Rechazado", class: "admin-status-rejected" },
};

// Filtros de estado: un solo control segmentado encima de la lista.
const TABS = [
  { id: "all", label: "Todos", Icon: Users },
  { id: "pending", label: "Pendientes", Icon: Clock },
  { id: "active", label: "Activos", Icon: UserCheck },
  { id: "rejected", label: "Rechazados", Icon: UserX },
];

// Tono con el que se pinta cada rol (avatar, chip y ficha). Va de arriba hacia
// abajo de la escala: violet, purple, indigo, blue, teal, amber. El verde queda
// para el estado "Activo", que es lo único que se lee de reojo en la lista.
const TONO_ROL = {
  admin: "violet",
  directivo: "purple",
  jefe_preceptores: "indigo",
  prosecretaria: "blue",
  preceptor: "teal",
  profesor: "amber",
  sin_rol: "gray",
};
const tonoRol = (role) => TONO_ROL[role] || "slate";

// Dos iniciales para el avatar, cuando no hay foto.
const iniciales = (u) =>
  `${(u?.name || "").trim().charAt(0)}${(u?.apellido || "").trim().charAt(0)}`.toUpperCase() || "?";

// Datos bajo el email: DNI (el backend solo lo trae desde jefe de preceptores)
// y la fecha que importa según el estado de la cuenta.
const metaUsuario = (u) => {
  const partes = [];
  if (u.dni) partes.push(`DNI ${u.dni}`);
  if (u.status === "pending") partes.push(`pide acceso el ${formatearFecha(u.created_at)}`);
  else if (u.last_login_at) partes.push(`último acceso ${formatearFecha(u.last_login_at)}`);
  return partes;
};

// Componente principal de administracion de usuarios
export default function AdminUsers() {
  const [confirmAction, confirmModal] = useConfirmAction();
  const [mostrarAviso, pedirConfirmacion, avisoModal] = useAviso();
  const [confirmarContrasena, modalPwd] = usePasswordPrompt();
  const { user } = useAuth();
  const [tab, setTab] = useState("all");
  // "cuentas" = la lista de siempre; "roles" = la pestaña de roles y permisos.
  const [vista, setVista] = useState("cuentas");
  const puedeRoles = ["admin", "jefe_preceptores"].includes(user?.role);
  const [users, setUsers] = useState([]);
  const [pending, setPending] = useState([]);
  const [loading, setLoading] = useState(true);
  const [selectedUser, setSelectedUser] = useState(null);
  const [assignments, setAssignments] = useState([]);
  // Snapshot de las asignaciones tal como las trajo el servidor al seleccionar
  // el usuario: sirve para deshacer el último guardado.
  const assignmentsOriginalRef = useRef([]);
  const [materiasByYear, setMateriasByYear] = useState(() => getCached("materias/todas") || {});
  const [showNewUser, setShowNewUser] = useState(false);
  const { montado: montadoNuevo, cerrando: cerrandoNuevo } = useCierreAnimado(showNewUser);
  const [newUser, setNewUser] = useState({ name: "", apellido: "", dni: "", email: "", password: "", role: "profesor" });
  const [roles, setRoles] = useState([]);
  const [search, setSearch] = useState("");
  const [showPwd, setShowPwd] = useState(false);
  const [assignRole, setAssignRole] = useState("profesor");
  // Rol pendiente en el panel de detalle: se guarda con "Cambiar rol", no al toque.
  const [rolEdit, setRolEdit] = useState(null);
  const [cambiandoRol, setCambiandoRol] = useState(false);
  const [selectedIds, setSelectedIds] = useState([]);
  const [bulkRole, setBulkRole] = useState("profesor");
  // Clave temporal del usuario abierto en el detalle: vive solo en pantalla,
  // se muestra una vez y se descarta al cambiar de usuario.
  const [claveTemp, setClaveTemp] = useState(null);
  const [generandoClave, setGenerandoClave] = useState(false);
  const [claveCopiada, setClaveCopiada] = useState(false);
  // Hay datos en pantalla (cache o ya cargados): las actualizaciones de fondo
  // no muestran el cartel de carga para no tapar la tabla.
  const hayDatosRef = useRef(!!getCached('adminUsers'));

  // Determina si el usuario logueado tiene permisos de administracion (admin, directivo o jefe_preceptores)
  const isAdmin = canManageRole(user?.role, "preceptor");

  // Carga los datos iniciales: usuarios, pendientes y roles.
  // Si hay copia guardada se muestra al instante y se actualiza por detrás;
  // si no, se muestra el spinner hasta que llegue lo primero.
  const fetchData = useCallback(async (force, claveCaché = "adminUsers") => {
    if (!force) {
      const cached = getCached(claveCaché);
      if (cached) {
        setUsers(cached.users);
        setPending(cached.pending);
        setRoles(cached.roles);
        setLoading(false);
        hayDatosRef.current = true;
        // OJO: no se retorna: abajo se pide lo fresco en segundo plano.
      }
    }
    if (!hayDatosRef.current) setLoading(true);
    try {
      const [usersData, pendingData, rolesData] = await Promise.all([
        apiAdminUsers(),
        // Approbation needs its own permission: a role without it still gets the
        // user list, just with an empty pending tab.
        apiAdminPendingUsers().catch(() => []),
        // Roles de sistema + los personalizados de "Roles y permisos", con su nombre.
        apiRoles(),
        cargarRoleDetalle(),
      ]);
      const users = usersData.data ?? usersData ?? [];
      const pending = pendingData.data ?? pendingData ?? [];
      setUsers(users);
      setPending(pending);
      setRoles(rolesData);
      setCache(claveCaché, { users, pending, roles: rolesData });
      hayDatosRef.current = true;
    } catch { /* sin datos del backend: la tabla queda vacía */ }
    setLoading(false);
  }, []);

  // Efecto inicial para cargar datos al montar el componente
  useEffect(() => { fetchData(); }, [fetchData]);

  // Si otra persona tocó usuarios, esta pantalla se actualiza sola.
  useAvisoDeCambios(
    "usuarios",
    ["usuarios"],
    () => fetchData(true)
  );

  // Aprueba o rechaza un usuario pendiente. Al activar, puede asignarse un rol real.
  const handleVerify = async (id, status, role) => {
    try {
      const label = status === "active" ? "activar" : "rechazar";
      const detalles = await confirmAction(`¿Por qué vas a ${label} este usuario?`, async (d) => {
        await apiAdminVerifyUser(id, status, d, role);
      });
      if (detalles === null) return;
      clearCache('adminUsers');
      await fetchData(true);
      showToast(status === "active" ? 'Usuario activado correctamente' : 'Usuario rechazado', 'success');
    } catch {
      mostrarAviso({ icon: "error", title: "Error al actualizar" });
    }
  };

  // Aprobar un pendiente: usa el rol elegido en el panel de detalle.
  const handleApproveSelected = () => {
    if (!selectedUser) return;
    handleVerify(selectedUser.id, "active", assignRole);
  };

  // Rol por defecto al aprobar rapido desde la lista.
  const defaultApproveRole = (u) =>
    roles.includes(u?.rol_solicitado) ? u.rol_solicitado : "profesor";

  // Seleccion multiple para acciones en lote.
  const toggleSelect = (id) =>
    setSelectedIds((prev) => prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]);
  const clearSelection = () => setSelectedIds([]);

  // Acciones en lote sobre los usuarios seleccionados.
  const applyBulk = async (action, ids, role) => {
    const map = Object.fromEntries(users.map((u) => [u.id, u]));
    const targets = ids
      .map((id) => map[id])
      .filter(Boolean);
    let toProcess;
    if (action === "delete") {
      toProcess = targets.filter((u) => u.status !== "pending" && u.id !== user?.id);
    } else {
      toProcess = targets.filter((u) => u.status === "pending");
    }
    if (toProcess.length === 0) {
      showToast("Ningún usuario seleccionado aplica para esta acción.", "info");
      return;
    }
    // Eliminación masiva: exige contraseña (el servidor la valida).
    if (action === "delete" && !(await confirmarContrasena())) return;
    const estado = action === "active" ? "activar" : action === "rejected" ? "rechazar" : "eliminar";
    const detalles = await confirmAction(
      `¿Por qué vas a ${estado} estos ${toProcess.length} usuario(s)?`,
      async (d) => {
        await Promise.all(toProcess.map((u) => {
          if (action === "delete") return apiAdminDeleteUser(u.id, d);
          return apiAdminVerifyUser(u.id, action, d, action === "active" ? role : undefined);
        }));
      }
    );
    if (detalles === null) return;
    clearSelection();
    clearCache('adminUsers');
    await fetchData(true);
    showToast(
      action === "active" ? "Usuarios activados correctamente"
        : action === "rejected" ? "Usuarios rechazados"
        : "Usuarios eliminados correctamente",
      "success"
    );
  };

  // Selecciona un usuario y carga sus asignaciones de cursos (+ ficha si es profesor)
  const selectUser = async (u) => {
    setSelectedUser(u);
    setClaveTemp(null);
    setClaveCopiada(false);
    setRolEdit(u?.role || null);
    // Para pendientes, preselecciona el rol solicitado si es valido
    if (u?.status === "pending" && roles.includes(u.rol_solicitado)) {
      setAssignRole(u.rol_solicitado);
    } else {
      setAssignRole("profesor");
    }
    // Catalogo de materias por año (para el selector de materias del profesor)
    if (!getCached("materias/todas")) {
      apiGet("/materias/todas")
        .then((d) => { setMateriasByYear(d || {}); setCache("materias/todas", d || {}); })
        .catch(() => {});
    }
    const [rows, ficha] = await Promise.all([
      apiAdminGetAssignments(u.id).catch(() => []),
      u?.role === "profesor" && u?.email
        ? apiGet(`/profesores?per_page=1&q=${encodeURIComponent(u.email)}`).catch(() => null)
        : Promise.resolve(null),
    ]);
    // Materias que ensena segun su ficha (vinculo por email)
    const prof = (ficha?.data || []).find(
      (p) => String(p.email || "").toLowerCase() === u.email.toLowerCase()
    );
    const fasigs = Array.isArray(prof?.asignaciones) ? prof.asignaciones : [];
    // Preselecciona en cada fila las materias de su ficha para ese curso
    const lista = Array.isArray(rows) ? rows : [];
    setAssignments(lista.map((r) => ({
      ...r,
      _key: r.id ?? `${Date.now()}-${Math.random()}`,
      mats:
        r.division && u.role === "profesor"
          ? fasigs
              .filter(
                (f) =>
                  Number(f.anio) === Number(r.anio) &&
                  String(f.division || "").toUpperCase() === String(r.division).toUpperCase()
              )
              .map((f) => Number(f.materia_id))
              .filter(Boolean)
          : [],
    })));
    assignmentsOriginalRef.current = lista;
  };

  // Agrega/quita una materia a una fila de asignacion (solo profesores)
  const addMat = (idx, idRaw) => {
    const id = Number(idRaw);
    if (!id) return;
    const copy = [...assignments];
    copy[idx] = { ...copy[idx], mats: [...(copy[idx].mats || []), id] };
    setAssignments(copy);
  };

  const removeMat = (idx, id) => {
    const copy = [...assignments];
    copy[idx] = { ...copy[idx], mats: (copy[idx].mats || []).filter((x) => x !== id) };
    setAssignments(copy);
  };

  // Agrega una asignacion de año/division vacia
  const [assignKeyCounter, setAssignKeyCounter] = useState(0);
  const addAssignment = () => {
    const key = Date.now() + assignKeyCounter;
    setAssignKeyCounter(k => k + 1);
    setAssignments([...assignments, { anio: 1, division: "", _key: key }]);
  };

  // Actualiza un campo especifico de una asignacion
  const updateAssignment = (idx, field, value) => {
    const copy = [...assignments];
    copy[idx] = { ...copy[idx], [field]: value };
    setAssignments(copy);
  };

  // Elimina una asignacion por su indice
  const removeAssignment = (idx) => {
    setAssignments(assignments.filter((_, i) => i !== idx));
  };

  // Guarda las asignaciones del usuario seleccionado
  const saveAssignments = async () => {
    if (!selectedUser) return;
    try {
        const detalles = await confirmAction('¿Qué cambios estás haciendo en las asignaciones?', async (d) => {
          const payload = assignments.map((a) => ({
            anio: Number(a.anio),
            division: a.division || null,
            ...(selectedUser.role === "profesor" && a.division
              ? { materias: (a.mats || []).map(Number) }
              : {}),
          }));
          await apiAdminSaveAssignments(selectedUser.id, payload, d);
        });
      if (detalles === null) return;
      const orig = selectedUser;
      ofrecerDeshacer(
        {
          etiqueta: `Asignaciones de ${orig?.name || ''} ${orig?.apellido || ''}`.trim(),
          deshacer: async () => {
            const revertPayload = (assignmentsOriginalRef.current || []).map((a) => ({
              anio: Number(a.anio ?? a.anio_num ?? 0),
              division: a.division || null,
              ...(orig?.role === "profesor" && a.division
                ? { materias: (a.mats || a.materias || []).map(Number) }
                : {}),
            }));
            await apiAdminSaveAssignments(orig.id, revertPayload, 'Deshace el último guardado');
            clearCache('adminUsers');
            await fetchData(true);
            if (selectedUser) setAssignments(revertPayload);
          },
        },
        'Cambios guardados correctamente'
      );
    } catch (e) {
      const msg = e?.data?.message || e?.message || "Error al guardar";
      showToast(msg, "error");
    }
  };

  // Crea un nuevo usuario desde el formulario del modal
  const createUser = async (e) => {
    e.preventDefault();
    const dniDigits = newUser.dni.replace(/\D/g, '');
    if (dniDigits.length < 7 || dniDigits.length > 8) {
      showToast('El DNI debe tener entre 7 y 8 dígitos.', 'error');
      return;
    }
    try {
      let createdId = null;
      const detalles = await confirmAction('¿Por qué creás este usuario?', async (d) => {
        const creado = await apiAdminCreateUser({ ...newUser, _detalles: d });
        createdId = creado?.id ?? creado?.user?.id ?? null;
      });
      if (detalles === null) return;
      setShowNewUser(false);
      setNewUser({ name: "", apellido: "", dni: "", email: "", password: "", role: "profesor" });
      clearCache('adminUsers');
      await fetchData(true);
      if (createdId) {
        // Deshacer elimina al usuario recién creado: exige contraseña.
        ofrecerDeshacer(
          {
            etiqueta: `Usuario ${newUser.name || ''} ${newUser.apellido || ''}`.trim(),
            exigePassword: true,
            deshacer: async () => {
              await apiAdminDeleteUser(createdId);
              clearCache('adminUsers');
              await fetchData(true);
            },
          },
          'Usuario creado correctamente'
        );
      } else {
        showToast('Usuario creado correctamente', 'success');
      }
    } catch (err) {
      const msg = err?.data?.errors
        ? Object.values(err.data.errors).flat()[0]
        : err?.data?.message || err?.message || "Error al crear usuario";
      showToast(msg, "error");
    }
  };

  // Elimina un usuario con confirmacion previa
  const deleteUser = async (id) => {
    const ok = await pedirConfirmacion({
      icon: "warning",
      title: "Eliminar usuario",
      text: "Esta acción no se puede deshacer",
      confirmText: "Eliminar",
      cancelText: "Cancelar",
      danger: true,
    });
    if (!ok) return;
    // Eliminación: exige contraseña (el servidor la valida).
    if (!(await confirmarContrasena())) return;
    try {
      const detalles = await confirmAction('¿Por qué eliminás este usuario?', async (d) => {
        await apiAdminDeleteUser(id, d);
      });
      if (detalles === null) return;
      clearCache('adminUsers');
      await fetchData(true);
      if (selectedUser?.id === id) setSelectedUser(null);
      showToast('Usuario eliminado correctamente', 'success');
    } catch (e) {
      showToast(e?.data?.message || e?.message || "Error al eliminar", "error");
    }
  };

  // Clave provisoria para quien no puede entrar y no tiene correo disponible:
  // se genera, se entrega en mano, y la app lo obliga a cambiarla al ingresar.
  const generarClaveTemporal = async () => {
    if (!selectedUser || generandoClave) return;
    const ok = await pedirConfirmacion({
      icon: "warning",
      title: "Generar clave temporal",
      text: `La clave actual de ${selectedUser.email} deja de servir y se cierran sus sesiones. La clave nueva se ve una sola vez.`,
      confirmText: "Generar",
      cancelText: "Cancelar",
    });
    if (!ok) return;
    setGenerandoClave(true);
    try {
      const r = await apiAdminClaveTemporal(selectedUser.id);
      setClaveTemp(r?.clave || "");
      setClaveCopiada(false);
      setSelectedUser((prev) => (prev ? { ...prev, debe_cambiar_password: true } : prev));
      clearCache("adminUsers");
      await fetchData(true);
      showToast("Clave temporal generada", "success");
    } catch (e) {
      showToast(e?.data?.message || e?.message || "No se pudo generar la clave temporal", "error");
    } finally {
      setGenerandoClave(false);
    }
  };

  // Sin portapapeles (permiso denegado o http sin https) no pasa nada malo: la
  // clave igual se puede copiar a mano desde el cuadro.
  const copiarClave = async () => {
    try {
      await navigator.clipboard.writeText(claveTemp);
      setClaveCopiada(true);
    } catch { /* se copia seleccionando el texto */ }
  };

  // Cambia el rol de una cuenta. Es la puerta de entrada de los roles personalizados:
  // el servidor exige confirmar la propia contraseña y que el rango alcance al rol
  // nuevo y al usuario editado.
  const cambiarRol = async () => {
    if (!selectedUser || !rolEdit || rolEdit === selectedUser.role || cambiandoRol) return;
    const destino = selectedUser;
    const anterior = destino.role;
    const nombre = `${destino.name || ""} ${destino.apellido || ""}`.trim();
    if (!(await confirmarContrasena())) return;
    const detalles = await confirmAction(
      `¿Por qué cambiás el rol de ${nombre || destino.email} de "${rolLabel(anterior)}" a "${rolLabel(rolEdit)}"?`,
      async (d) => apiAdminUpdateUserRole(destino.id, rolEdit, d)
    );
    if (detalles === null) return;
    setCambiandoRol(true);
    try {
      clearCache("adminUsers");
      await fetchData(true, "adminUsersVista");
      setSelectedUser((prev) => (prev && prev.id === destino.id ? { ...prev, role: rolEdit } : prev));
      ofrecerDeshacer(
        {
          etiqueta: `Rol de ${nombre || destino.email}`,
          exigePassword: true,
          deshacer: async () => {
            await apiAdminUpdateUserRole(destino.id, anterior, "Deshace el cambio de rol");
            setSelectedUser((prev) => (prev && prev.id === destino.id ? { ...prev, role: anterior } : prev));
            clearCache("adminUsers");
            await fetchData(true, "adminUsersVista");
          },
        },
        `Rol de ${nombre || destino.email}: ahora ${rolLabel(rolEdit)}`
      );
    } catch (e) {
      showToast(mensajeAmable(e, "No se pudo cambiar el rol."), "error");
    } finally {
      setCambiandoRol(false);
    }
  };

  const activeUsers = users.filter((u) => u.status !== "pending");
  const rejectedUsers = users.filter((u) => u.status === "rejected");

  const baseList =
    tab === "pending" ? pending :
    tab === "active" ? activeUsers :
    tab === "rejected" ? rejectedUsers :
    users;

  const q = search.trim().toLowerCase();
  const userList = q
    ? baseList.filter((u) =>
        `${u.name} ${u.apellido}`.toLowerCase().includes(q) ||
        (u.email || "").toLowerCase().includes(q) ||
        (rolLabel(u.role)).toLowerCase().includes(q)
      )
    : baseList;

  const showToast = useToast();
  const { ofrecerDeshacer } = useDeshacerAviso();
  const exportUsers = userList.map(u => ({
    nombre: u.name, email: u.email, rol: u.role, estado: u.status || "active",
  }));

  // Chip de estado y tono del usuario abierto en el panel de detalle.
  const detalleEstado = STATUS_LABELS[selectedUser?.status] || STATUS_LABELS.active;
  const detalleTono = tonoRol(selectedUser?.role);

  return (
    <div className="admin-page sys-pagina">
      <Breadcrumbs
        items={
          puedeRoles && vista === "roles"
            ? [{ label: "Usuarios", onClick: () => setVista("cuentas") }, { label: "Roles y permisos" }]
            : [{ label: "Usuarios" }]
        }
      />
      <div className="admin-header">
        <div className="admin-header-izq">
          {puedeRoles && vista === "roles" && (
            <button
              type="button"
              className="admin-volver"
              onClick={() => setVista("cuentas")}
              title="Volver a la lista de cuentas"
            >
              <ArrowLeft size={17} /> Volver a Cuentas
            </button>
          )}
          <h1 className="admin-title">
            <Shield className="admin-title-icon" size={28} />
            Administrar Usuarios
          </h1>
        </div>
        {vista === "cuentas" && (
        <div className="admin-header-actions">
          {puedeRoles && (
            <button
              type="button"
              className="admin-entrar-roles"
              onClick={() => setVista("roles")}
              title="Elegir qué puede hacer cada rol y crear roles nuevos"
            >
              <ShieldCheck size={17} />
              <span className="admin-entrar-roles-texto">
                Roles y permisos
                <small>Qué puede hacer cada rol</small>
              </span>
              <ChevronRight size={16} className="admin-entrar-roles-flecha" />
            </button>
          )}
          <button onClick={() => fetchData(true)} className="admin-btn-ghost">
            <RefreshCw size={16} /> Refrescar
          </button>
          <ExportButton data={exportUsers} filename="usuarios" headers={{
            nombre: "Nombre",
            email: "Email",
            rol: "Rol",
            estado: "Estado",
          }} />
          {isAdmin && (
            <button onClick={() => setShowNewUser(true)} className="admin-btn-primary">
              <Plus size={18} /> Nuevo usuario
            </button>
          )}
        </div>
        )}
      </div>

      {vista === "roles" ? (
        <RolesPermisos />
      ) : (
        <>
      <div className="admin-filtros">
        <div className="admin-segmento" role="group" aria-label="Filtrar cuentas por estado">
          {TABS.map(({ id, label, Icon }) => {
            const cuenta =
              id === "all" ? users.length :
              id === "pending" ? pending.length :
              id === "active" ? activeUsers.length :
              rejectedUsers.length;
            return (
              <button
                key={id}
                type="button"
                onClick={() => setTab(id)}
                aria-pressed={tab === id}
                className={`admin-segmento-item admin-segmento-${id} ${tab === id ? "admin-segmento-activo" : ""}`}
              >
                <Icon size={15} />
                <span>{label}</span>
                <span className="admin-segmento-cuenta">{cuenta}</span>
                {id === "pending" && cuenta > 0 && <span className="admin-segmento-alerta" title="Hay cuentas esperando aprobación" />}
              </button>
            );
          })}
        </div>
        <div className="admin-search-wrap">
          <Search size={16} className="admin-search-icon" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Buscar por nombre, email o rol"
            aria-label="Buscar usuarios"
            className="admin-search-input"
          />
          {search && (
            <button type="button" onClick={() => setSearch("")} className="admin-search-clear" aria-label="Limpiar búsqueda">
              <X size={14} />
            </button>
          )}
        </div>
      </div>

      <div className="admin-layout">
        <div className="admin-panel">
          <div className="admin-panel-head">
            <span className="admin-panel-count">
              <Users size={14} />
              {userList.length} cuenta{userList.length === 1 ? "" : "s"}
              {q && userList.length !== baseList.length && (
                <span className="admin-panel-count-extra">de {baseList.length}</span>
              )}
            </span>
            {isAdmin && userList.length > 0 && (
              <label className="admin-select-all" title="Seleccionar todas las cuentas visibles">
                <input
                  type="checkbox"
                  checked={userList.length > 0 && userList.every((u) => selectedIds.includes(u.id))}
                  onChange={(e) => {
                    if (e.target.checked) {
                      setSelectedIds((prev) => Array.from(new Set([...prev, ...userList.map((u) => u.id)])));
                    } else {
                      setSelectedIds((prev) => prev.filter((id) => !userList.some((u) => u.id === id)));
                    }
                  }}
                />
                <span>Seleccionar todo</span>
              </label>
            )}
          </div>
          {isAdmin && selectedIds.length > 0 && (
            <div className="admin-bulk-bar">
              <span className="admin-bulk-count">{selectedIds.length} seleccionado{selectedIds.length === 1 ? "" : "s"}</span>
              <div className="admin-bulk-actions">
                <label className="admin-bulk-role">
                  Rol:
                  <select value={bulkRole} onChange={(e) => setBulkRole(e.target.value)} className="admin-input admin-input-sm">
                    <OpcionesRol roles={roles} actor={user?.role} soloAsignables />
                  </select>
                </label>
                <button onClick={() => applyBulk("active", selectedIds, bulkRole)} className="admin-btn-primary admin-btn-sm">
                  <UserCheck size={14} /> Aprobar
                </button>
                <button onClick={() => applyBulk("rejected", selectedIds)} className="admin-btn-secondary admin-btn-sm">
                  <UserX size={14} /> Rechazar
                </button>
                <button onClick={() => applyBulk("delete", selectedIds)} className="admin-btn-danger admin-btn-sm">
                  <Trash2 size={14} /> Eliminar
                </button>
                <button onClick={clearSelection} className="admin-bulk-clear">
                  <X size={14} /> Limpiar
                </button>
              </div>
            </div>
          )}
          <div className="admin-panel-body">
            {loading ? (
              <div className="admin-loading">Cargando datos...</div>
            ) : userList.length === 0 ? (
              <EmptyState icon={tab === "pending" ? Clock : Users} title={tab === "pending" ? "Sin pendientes" : "No hay usuarios"} description={tab === "pending" ? "No hay cuentas pendientes de verificación." : "No hay usuarios registrados en el sistema."} compact />
            ) : (
              userList.map((u) => {
                const st = STATUS_LABELS[u.status] || STATUS_LABELS.active;
                const isSelected = selectedUser?.id === u.id;
                const tono = tonoRol(u.role);
                const meta = metaUsuario(u);
                return (
                  <div key={u.id}
                    onClick={() => selectUser(u)}
                    className={`admin-user-item ${isSelected ? "admin-user-item-selected" : ""} ${selectedIds.includes(u.id) ? "admin-user-item-checked" : ""}`}
                  >
                    {isAdmin && (
                      <label className="admin-user-check" onClick={(e) => e.stopPropagation()}>
                        <input
                          type="checkbox"
                          checked={selectedIds.includes(u.id)}
                          onChange={() => toggleSelect(u.id)}
                        />
                      </label>
                    )}
                    <Avatar
                      id={u.id}
                      version={u.avatar_version}
                      titulo={`${u.name} ${u.apellido}`.trim()}
                      className={`admin-avatar admin-tono-${tono}`}
                    >
                      {iniciales(u)}
                    </Avatar>
                    <div className="admin-user-item-head">
                      <div className="admin-user-item-texto">
                        <div className="admin-user-item-fila1">
                          <span className="admin-user-item-name">{u.name} {u.apellido}</span>
                          <span className={`admin-chip admin-chip-role admin-tono-${tono}`}>
                            {rolLabel(u.role)}
                          </span>
                          <span className={`admin-chip admin-chip-status ${st.class}`}>
                            {st.label}
                          </span>
                          {u.debe_cambiar_password && (
                            <span className="admin-chip admin-chip-clave" title="Tiene clave temporal: el sistema le pide una propia al ingresar">
                              Clave provisoria
                            </span>
                          )}
                          {u.status === "pending" && u.rol_solicitado && (
                            <span className="admin-chip admin-chip-request" title="Rol solicitado por el usuario">
                              Solicitó: {u.rol_solicitado}
                            </span>
                          )}
                        </div>
                        <div className="admin-user-item-email">
                          {u.email}
                          {meta.length > 0 && (
                            <span className="admin-user-item-meta"> · {meta.join(" · ")}</span>
                          )}
                        </div>
                      </div>
                      <div className="admin-user-item-actions">
                        {isAdmin && u.status === "pending" && (
                          <>
                            <button onClick={(e) => { e.stopPropagation(); handleVerify(u.id, "active", defaultApproveRole(u)); }} className="admin-icon-btn admin-icon-btn-approve" title="Aprobar">
                              <UserCheck size={16} />
                            </button>
                            <button onClick={(e) => { e.stopPropagation(); handleVerify(u.id, "rejected"); }} className="admin-icon-btn admin-icon-btn-reject" title="Rechazar">
                              <UserX size={16} />
                            </button>
                          </>
                        )}
                        {isAdmin && u.status === "active" && u.id !== user?.id && canManageRole(user?.role, u.role) && (
                          <button onClick={(e) => { e.stopPropagation(); deleteUser(u.id); }} className="admin-icon-btn admin-icon-btn-delete" title="Eliminar">
                            <Trash2 size={16} />
                          </button>
                        )}
                      </div>
                    </div>
                  </div>
                );
              })
            )}
          </div>
        </div>

        <div className="admin-panel">
          {selectedUser ? (
            <>
              <div className="admin-panel-head admin-panel-head-lg">
                <div className="admin-detalle-top">
                  <Avatar
                    id={selectedUser.id}
                    version={selectedUser.avatar_version}
                    titulo={`${selectedUser.name} ${selectedUser.apellido}`.trim()}
                    className={`admin-avatar admin-avatar-lg admin-tono-${detalleTono}`}
                  >
                    {iniciales(selectedUser)}
                  </Avatar>
                  <div className="admin-detalle-id">
                    <h3 className="admin-panel-title">
                      {selectedUser.name} {selectedUser.apellido}
                    </h3>
                    <p className="admin-panel-subtitle">{selectedUser.email}</p>
                    <div className="admin-detalle-chips">
                      <span className={`admin-chip admin-chip-role admin-tono-${detalleTono}`}>
                        {rolLabel(selectedUser.role)}
                      </span>
                      <span className={`admin-chip admin-chip-status ${detalleEstado.class}`}>
                        {detalleEstado.label}
                      </span>
                    </div>
                  </div>
                </div>
              </div>

              <div className="admin-panel-body admin-panel-body-pad">
                {selectedUser.status === "active" && selectedUser.id !== user?.id && canManageRole(user?.role, selectedUser.role) && (
                  <div className="admin-rol-caja">
                    <h4 className="admin-section-title">
                      <Shield size={16} /> Rol de la cuenta
                    </h4>
                    <p className="admin-section-hint">
                      El rol define qué puede hacer y sobre qué cursos o alumnos. Los roles
                      personalizados se crean y ajustan en "Roles y permisos".
                    </p>
                    <div className="admin-rol-fila">
                      <select
                        value={rolEdit || selectedUser.role}
                        onChange={(e) => setRolEdit(e.target.value)}
                        className="admin-input admin-input-role"
                        aria-label="Nuevo rol de la cuenta"
                      >
                        <OpcionesRol roles={roles} actor={user?.role} incluir={selectedUser.role} />
                      </select>
                      <button
                        onClick={cambiarRol}
                        disabled={rolEdit === selectedUser.role || !rolEdit || cambiandoRol}
                        className="admin-btn-primary"
                        title="Pedirá confirmar tu contraseña"
                      >
                        <ShieldCheck size={16} /> {cambiandoRol ? "Cambiando…" : "Cambiar rol"}
                      </button>
                    </div>
                    {rolEdit !== selectedUser.role && (
                      <p className="admin-rol-nota">
                        {`${rolLabel(selectedUser.role)} → ${rolLabel(rolEdit)}`}
                        {ROLE_LEVEL[rolEdit] === undefined && " (rol personalizado)"}
                      </p>
                    )}
                  </div>
                )}

                {selectedUser.status === "pending" ? (
                  <>
                    <h4 className="admin-section-title">
                      <Clock size={16} /> Cuenta pendiente de aprobación
                    </h4>
                    <div className="admin-request-box">
                      <span className="admin-request-label">Rol solicitado por el usuario:</span>
                      <p className="admin-request-text">{selectedUser.rol_solicitado || "No especificó ninguno"}</p>
                    </div>

                    {isAdmin && (
                      <>
                        <label className="admin-label">Asignar rol al aprobar</label>
                        <select value={assignRole} onChange={(e) => setAssignRole(e.target.value)}
                          className="admin-input">
                          <OpcionesRol roles={roles} actor={user?.role} soloAsignables />
                        </select>
                        <div className="admin-form-actions">
                          <button onClick={handleApproveSelected} className="admin-btn-primary admin-btn-submit">
                            <UserCheck size={16} /> Aprobar y asignar rol
                          </button>
                          <button onClick={() => handleVerify(selectedUser.id, "rejected")} className="admin-btn-secondary">
                            <UserX size={16} /> Rechazar
                          </button>
                        </div>
                      </>
                    )}
                  </>
                ) : esRolConAsignaciones(selectedUser.role) ? (
                  <>
                    <h4 className="admin-section-title">
                      <BookOpen size={16} /> Asignaciones (años/divisiones)
                    </h4>
                    <p className="admin-section-hint">
                      {selectedUser.role === "profesor"
                        ? "El profesor verá solo los cursos y alumnos de estas asignaciones. Las materias que agregues se guardan en su ficha (módulo Profesores)."
                        : `${rolLabel(selectedUser.role)} verá solo los cursos y alumnos de estas asignaciones cuando su alcance esté en "Solo los asignados".`}
                    </p>

                    <div className="admin-assign-list">
                      {assignments.length === 0 && (
                        <p className="admin-assign-empty">Este usuario aún no tiene asignaciones.</p> 
                      )}
                      {assignments.map((a, i) => {
                        const esProfe = selectedUser.role === "profesor";
                        const conDivision = String(a.division || "") !== "";
                        const matsAnio = materiasByYear[a.anio] || [];
                        const disponibles = matsAnio.filter((m) => !(a.mats || []).includes(m.id));
                        return (
                          <div key={a._key ?? i} className="admin-assign-row admin-assign-row-wrap">
                            <select value={a.anio} onChange={(e) => updateAssignment(i, "anio", e.target.value)} className="admin-select">
                              {[1, 2, 3, 4, 5, 6, 7].map((n) => <option key={n} value={n}>{cursoLabel({ anio: n, division: null })}</option>)}
                            </select>
                             <select value={a.division || ""} onChange={(e) => updateAssignment(i, "division", e.target.value)} className="admin-select admin-select-wide">
                               <option value="">Todas</option>
                               {["1","2","3","4","5","6","7","8","9"].map(d => <option key={d} value={d}>{d}</option>)}
                             </select>
                            <button onClick={() => removeAssignment(i)} className="admin-icon-btn admin-icon-btn-remove">
                              <Trash2 size={14} />
                            </button>
                            {esProfe && conDivision && (
                              <div className="admin-assign-mats">
                                {(a.mats || []).map((mid) => {
                                  const m = matsAnio.find((x) => x.id === mid);
                                  return (
                                    <span key={mid} className="admin-assign-mat-chip">
                                      {m?.nombre || `Materia ${mid}`}
                                      <button
                                        type="button"
                                        aria-label={`Quitar ${m?.nombre || mid}`}
                                        className="admin-assign-mat-x"
                                        onClick={() => removeMat(i, mid)}
                                      >×</button>
                                    </span>
                                  );
                                })}
                                {disponibles.length > 0 && (
                                  <select
                                    value=""
                                    className="admin-assign-mat-add"
                                    onChange={(e) => addMat(i, e.target.value)}
                                    aria-label="Agregar materia"
                                  >
                                    <option value="">+ Materia</option>
                                    {disponibles.map((m) => (
                                      <option key={m.id} value={m.id}>{m.nombre}</option>
                                    ))}
                                  </select>
                                )}
                              </div>
                            )}
                            {esProfe && !conDivision && (
                              <p className="admin-assign-hint">Elegí una división para asignar materias.</p>
                            )}
                          </div>
                        );
                      })}
                      <button onClick={addAssignment} className="admin-add-link">
                        <Plus size={16} /> Agregar año
                      </button>
                    </div>

                    <button onClick={saveAssignments} className="admin-btn-primary admin-btn-save">
                      <Save size={16} /> Guardar asignaciones
                    </button>
                  </>
                ) : (
                  <div className="admin-info-box">
                    Este rol (<b>{rolLabel(selectedUser.role)}</b>) tiene acceso a todos los cursos: no necesita asignaciones.
                  </div>
                )}

                {selectedUser.status === "active" && selectedUser.id !== user?.id && canManageRole(user?.role, selectedUser.role) && (
                  <div className="admin-acceso">
                    <h4 className="admin-section-title admin-section-title-mt">
                      <KeyRound size={16} /> Acceso
                    </h4>
                    <p className="admin-section-hint">
                      Si no puede entrar y la recuperación por email no está disponible en el colegio,
                      generá una clave provisoria para entregársela en mano. El sistema le va a exigir
                      poner una propia en su próximo ingreso.
                    </p>

                    {selectedUser.debe_cambiar_password && !claveTemp && (
                      <p className="admin-acceso-alerta">
                        Ahora tiene una clave temporal: le falta cambiarla.
                      </p>
                    )}

                    {claveTemp && (
                      <div className="admin-clave-caja">
                        <div className="admin-clave-valor">{claveTemp}</div>
                        <button onClick={copiarClave} className="admin-btn-secondary admin-btn-sm">
                          {claveCopiada ? <Check size={14} /> : <Copy size={14} />}
                          {claveCopiada ? "Copiada" : "Copiar"}
                        </button>
                        <p className="admin-clave-nota">
                          Anotala y entrégala en mano. No se vuelve a mostrar: si se pierde, generá otra.
                        </p>
                      </div>
                    )}

                    <button
                      onClick={generarClaveTemporal}
                      disabled={generandoClave}
                      className="admin-btn-secondary"
                    >
                      <KeyRound size={16} /> {generandoClave ? "Generando…" : "Generar clave temporal"}
                    </button>
                  </div>
                )}
              </div>
            </>
          ) : (
            <div className="admin-panel-empty">
              <span className="admin-empty-orb"><Users size={22} /></span>
              <p>Seleccioná un usuario para ver y editar sus asignaciones</p>
            </div>
          )}
        </div>
      </div>
        </>
      )}

      {montadoNuevo && (
        <div
          className={`admin-modal-backdrop sys-capa${cerrandoNuevo ? " sys-cerrando" : ""}`}
          onClick={() => setShowNewUser(false)}
        >
          <div
            className={`admin-modal sys-dialogo${cerrandoNuevo ? " sys-cerrando" : ""}`}
            role="dialog" aria-modal="true"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="admin-modal-top-bar" />

            <div className="admin-modal-hero">
              <div className="admin-halo admin-halo-blue" />
              <div className="admin-halo admin-halo-purple" />

              <div className="admin-modal-header">
                <h3 className="admin-modal-title">Nuevo usuario</h3>
                <button
                  type="button"
                  onClick={() => setShowNewUser(false)}
                  className="admin-modal-close"
                ><X size={20} /></button>
              </div>
            </div>

            <form onSubmit={createUser} className="admin-modal-body">
              <div className="admin-card admin-card-stack">
                <div className="admin-grid-2">
                  <div>
                    <label className="admin-label">Nombre</label>
                    <input value={newUser.name} onChange={(e) => setNewUser({ ...newUser, name: e.target.value })}
                      className="admin-input" required />
                  </div>
                  <div>
                    <label className="admin-label">Apellido</label>
                    <input value={newUser.apellido} onChange={(e) => setNewUser({ ...newUser, apellido: e.target.value })}
                      className="admin-input" required />
                  </div>
                </div>
                <div>
                  <label className="admin-label">Email</label>
                  <input type="email" value={newUser.email} onChange={(e) => setNewUser({ ...newUser, email: e.target.value })}
                    className="admin-input" required />
                </div>
                <div>
                  <label className="admin-label">DNI</label>
                  <input value={newUser.dni} onChange={(e) => setNewUser({ ...newUser, dni: e.target.value.replace(/\D/g, '') })} maxLength={8}
                    className="admin-input" />
                </div>
              </div>

              <div className="admin-card admin-card-stack">
                <div className="admin-grid-2">
                  <div>
                    <label className="admin-label">Contraseña</label>
                    <div className="admin-input-wrap">
                      <input type={showPwd ? 'text' : 'password'} value={newUser.password}
                        onChange={(e) => setNewUser({ ...newUser, password: e.target.value })}
                        className="admin-input admin-input-password" required />
                      <button type="button" onClick={() => setShowPwd(s => !s)}
                        className="admin-input-toggle">
                        {showPwd ? <EyeOff size={18} /> : <Eye size={18} />}
                      </button>
                    </div>
                  </div>
                  <div>
                    <label className="admin-label">Rol</label>
                    <select value={newUser.role} onChange={(e) => setNewUser({ ...newUser, role: e.target.value })}
                      className="admin-input">
                      <OpcionesRol roles={roles} actor={user?.role} soloAsignables />
                    </select>
                  </div>
                </div>
              </div>

              <div className="admin-form-actions">
                <button type="submit" className="admin-btn-primary admin-btn-submit">Crear</button>
                <button type="button" onClick={() => setShowNewUser(false)} className="admin-btn-secondary">Cancelar</button>
              </div>
            </form>
          </div>
        </div>
      )}
      {confirmModal}
      {avisoModal}
      {modalPwd}
    </div>
  );
}
