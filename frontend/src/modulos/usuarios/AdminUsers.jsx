// Pagina de administracion de usuarios del sistema.
// Permite ver, aprobar/rechazar, crear, eliminar usuarios y asignar cursos/divisiones.
import React, { useEffect, useState, useCallback, useRef } from "react";
import { motion } from "framer-motion";
import { UserCheck, UserX, Users, Clock, Shield, BookOpen, Save, Search, Plus, Trash2, RefreshCw, X, Eye, EyeOff } from "lucide-react";
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
  apiRoles,
} from "../../compartido/api/auth";
import { useAuth } from "../auth/AuthContext";
import { canManageRole } from "../auth/roles";
import Breadcrumbs from "../../compartido/componentes/Common/Breadcrumbs";
import EmptyState from "../../compartido/componentes/Common/EmptyState";
import { useToast } from "../../compartido/componentes/Common/Toast";
import { useDeshacerAviso } from "../../compartido/lib/deshacer.jsx";
import ExportButton from "../../compartido/componentes/Common/ExportButton";
import { getCached, setCache, clearCache } from "../../compartido/lib/pageCache";
import { apiGet } from "../../compartido/lib/api";
import useConfirmAction from "../../compartido/hooks/useConfirmAction";
import { cursoLabel } from "../../compartido/lib/cursos";
import "./AdminUsers.css";

// Etiquetas de roles para mostrar en la interfaz
const ROLE_LABELS = {
  admin: "Admin",
  directivo: "Directivo",
  jefe_preceptores: "Jefe de Preceptores",
  preceptor: "Preceptor",
  prosecretaria: "Prosecretaria",
  profesor: "Profesor",
  sin_rol: "Sin rol",
};

// Roles que se pueden asignar desde esta pantalla: solo operativos
// (sin jefe de preceptores ni gestión global).
const ROLES_ASIGNABLES = ["profesor", "preceptor", "prosecretaria"];

// Etiquetas y estilos para los estados de los usuarios
const STATUS_LABELS = {
  pending: { label: "Pendiente", class: "admin-status-pending" },
  active: { label: "Activo", class: "admin-status-active" },
  rejected: { label: "Rechazado", class: "admin-status-rejected" },
};

// Componente principal de administracion de usuarios
export default function AdminUsers() {
  const [confirmAction, confirmModal] = useConfirmAction();
  const [mostrarAviso, pedirConfirmacion, avisoModal] = useAviso();
  const [confirmarContrasena, modalPwd] = usePasswordPrompt();
  const { user } = useAuth();
  const [tab, setTab] = useState("all");
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
  const [newUser, setNewUser] = useState({ name: "", apellido: "", dni: "", email: "", password: "", role: "profesor" });
  const [roles, setRoles] = useState([]);
  const [search, setSearch] = useState("");
  const [showPwd, setShowPwd] = useState(false);
  const [assignRole, setAssignRole] = useState("profesor");
  const [selectedIds, setSelectedIds] = useState([]);
  const [bulkRole, setBulkRole] = useState("profesor");
  // Hay datos en pantalla (cache o ya cargados): las actualizaciones de fondo
  // no muestran el cartel de carga para no tapar la tabla.
  const hayDatosRef = useRef(!!getCached('adminUsers'));

  // Determina si el usuario logueado tiene permisos de administracion (admin, directivo o jefe_preceptores)
  const isAdmin = canManageRole(user?.role, "preceptor");

  // Carga los datos iniciales: usuarios, pendientes y roles.
  // Si hay copia guardada se muestra al instante y se actualiza por detrás;
  // si no, se muestra el spinner hasta que llegue lo primero.
  const fetchData = useCallback(async (force) => {
    if (!force) {
      const cached = getCached('adminUsers');
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
        apiAdminPendingUsers(),
        apiRoles(),
      ]);
      const users = usersData.data ?? usersData ?? [];
      const pending = pendingData.data ?? pendingData ?? [];
      setUsers(users);
      setPending(pending);
      setRoles(rolesData);
      setCache('adminUsers', { users, pending, roles: rolesData });
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
        (ROLE_LABELS[u.role] || u.role).toLowerCase().includes(q)
      )
    : baseList;

  const showToast = useToast();
  const { ofrecerDeshacer } = useDeshacerAviso();
  const exportUsers = userList.map(u => ({
    nombre: u.name, email: u.email, rol: u.role, estado: u.status || "active",
  }));

  return (
    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="admin-page">
      <Breadcrumbs items={[{ label: "Usuarios" }]} />
      <div className="admin-header">
        <h1 className="admin-title">
          <Shield className="admin-title-icon" size={28} />
          Administrar Usuarios
        </h1>
        <div className="admin-header-actions">
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
      </div>

      <div className="admin-tabs-bar">
        <div className="admin-tabs-group">
          <button onClick={() => setTab("all")} className={`admin-tab ${tab === "all" ? "admin-tab-active-blue" : "admin-tab-idle"}`}>
            <Users size={16} className="admin-tab-icon" /> Todos ({users.length})
          </button>
          <button onClick={() => setTab("pending")} className={`admin-tab ${tab === "pending" ? "admin-tab-active-yellow" : "admin-tab-idle"}`}>
            <Clock size={16} className="admin-tab-icon" /> Pendientes ({pending.length})
          </button>
          <button onClick={() => setTab("active")} className={`admin-tab ${tab === "active" ? "admin-tab-active-green" : "admin-tab-idle"}`}>
            <UserCheck size={16} className="admin-tab-icon" /> Activos ({activeUsers.length})
          </button>
          <button onClick={() => setTab("rejected")} className={`admin-tab ${tab === "rejected" ? "admin-tab-active-red" : "admin-tab-idle"}`}>
            <UserX size={16} className="admin-tab-icon" /> Rechazados ({rejectedUsers.length})
          </button>
        </div>
        <div className="admin-search-wrap">
          <Search size={16} className="admin-search-icon" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Buscar por nombre, email o rol"
            className="admin-search-input"
          />
        </div>
      </div>

      <div className="admin-layout">
        <div className="admin-panel">
          <div className="admin-panel-head">
            <span className="admin-panel-count">{userList.length} usuario{userList.length === 1 ? "" : "s"}</span>
            {isAdmin && userList.length > 0 && (
              <label className="admin-select-all" title="Seleccionar todos">
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
            {search && (
              <button onClick={() => setSearch("")} className="admin-clear-btn">Limpiar búsqueda</button>
            )}
          </div>
          {isAdmin && selectedIds.length > 0 && (
            <div className="admin-bulk-bar">
              <span className="admin-bulk-count">{selectedIds.length} seleccionado{selectedIds.length === 1 ? "" : "s"}</span>
              <div className="admin-bulk-actions">
                <label className="admin-bulk-role">
                  Rol:
                  <select value={bulkRole} onChange={(e) => setBulkRole(e.target.value)} className="admin-input admin-input-sm">
                    {roles.filter((r) => ROLES_ASIGNABLES.includes(r) && canManageRole(user?.role, r)).map((r) => (
                      <option key={r} value={r}>{ROLE_LABELS[r] || r}</option>
                    ))}
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
                    <div className="admin-user-item-head">
                      <div>
                        <div className="admin-user-item-name">{u.name} {u.apellido}</div>
                        <div className="admin-user-item-email">{u.email}</div>
                        <div className="admin-user-item-tags">
                          <span className="admin-chip-role">
                            {ROLE_LABELS[u.role] || u.role}
                          </span>
                          <span className={`admin-chip-status ${st.class}`}>
                            {st.label}
                          </span>
                          {u.status === "pending" && u.rol_solicitado && (
                            <span className="admin-chip-request" title="Rol solicitado por el usuario">
                              Solicitó: {u.rol_solicitado}
                            </span>
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
                <h3 className="admin-panel-title">
                  {selectedUser.name} {selectedUser.apellido}
                </h3>
                <p className="admin-panel-subtitle">{selectedUser.email}</p>
                <span className="admin-chip-role admin-chip-role-block">
                  {ROLE_LABELS[selectedUser.role] || selectedUser.role}
                </span>
              </div>

              <div className="admin-panel-body admin-panel-body-pad">
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
                          {roles.filter((r) => ROLES_ASIGNABLES.includes(r) && canManageRole(user?.role, r)).map((r) => (
                            <option key={r} value={r}>{ROLE_LABELS[r] || r}</option>
                          ))}
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
                ) : ['profesor', 'preceptor', 'prosecretaria'].includes(selectedUser.role) ? (
                  <>
                    <h4 className="admin-section-title">
                      <BookOpen size={16} /> Asignaciones (años/divisiones)
                    </h4>
                    <p className="admin-section-hint">
                      {selectedUser.role === "profesor"
                        ? "El profesor verá solo los cursos y alumnos de estas asignaciones. Las materias que agregues se guardan en su ficha (módulo Profesores)."
                        : selectedUser.role === "prosecretaria"
                          ? "La prosecretaria verá solo los años/cursos asignados."
                          : "El preceptor verá solo los años/cursos asignados."}
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
                    Este rol (<b>{ROLE_LABELS[selectedUser.role] || selectedUser.role}</b>) tiene acceso total y no requiere asignaciones de cursos.
                  </div>
                )}
              </div>
            </>
          ) : (
            <div className="admin-panel-empty">
              Seleccioná un usuario para ver y editar sus asignaciones
            </div>
          )}
        </div>
      </div>

      {showNewUser && (
        <div
          className="admin-modal-backdrop"
          onClick={() => setShowNewUser(false)}
        >
          <motion.div
            initial={false}
            exit={{ opacity: 0, scale: 0.96, y: 12 }}
            transition={{ duration: 0.24, ease: [0.25, 0.46, 0.45, 0.94] }}
            className="admin-modal"
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
                      {roles.filter((r) => ROLES_ASIGNABLES.includes(r) && canManageRole(user?.role, r)).map((r) => <option key={r} value={r}>{ROLE_LABELS[r] || r}</option>)}
                    </select>
                  </div>
                </div>
              </div>

              <div className="admin-form-actions">
                <button type="submit" className="admin-btn-primary admin-btn-submit">Crear</button>
                <button type="button" onClick={() => setShowNewUser(false)} className="admin-btn-secondary">Cancelar</button>
              </div>
            </form>
          </motion.div>
        </div>
      )}
      {confirmModal}
      {avisoModal}
      {modalPwd}
    </motion.div>
  );
}
