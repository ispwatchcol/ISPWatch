const getStoredUser = () => {
    const local = localStorage.getItem('userData');
    if (local) return JSON.parse(local);
    
    const session = sessionStorage.getItem('userData');
    if (session) return JSON.parse(session);
    
    return null;
};

export const hasPermission = (permission) => {
    const user = getStoredUser();
    if (!user) return false;

    // Admin bypass: role_id==1 OR role_name==='Administrador' (guards against seeder ID mismatches)
    if (user.role_id == 1 || user.role_name === 'Administrador' || (user.permissions && user.permissions.includes('*'))) {
        return true;
    }

    return user.permissions && user.permissions.includes(permission);
};

export const getUserRole = () => {
    const user = getStoredUser();
    return user ? user.role_id : null;
};

export const getCurrentUserId = () => {
    const user = getStoredUser();
    return user ? user.id : null;
};

export const isStaffOrAdmin = () => {
    const roleId = getUserRole();
    return roleId == 1 || roleId == 2;
};

export const hasStaffProfile = () => {
    const user = getStoredUser();
    return user ? user.has_staff_profile === true : false;
};
