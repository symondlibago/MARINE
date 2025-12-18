import React, { useState, useEffect } from 'react';
import { X, User, Lock, LogOut, ChevronRight, Save, ArrowLeft, ShieldCheck, Loader2, Mail, KeyRound, Eye, EyeOff } from 'lucide-react';
import { authAPI } from '../pages/api'; 

export default function ProfileModal({ isOpen, onClose, onLogout }) {
  const [view, setView] = useState('menu'); // 'menu', 'edit', 'password', 'email'
  const [user, setUser] = useState({ name: '', email: '' });
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState({ type: '', text: '' });

  // Form States
  const [newName, setNewName] = useState('');
  const [passwords, setPasswords] = useState({ current: '', new: '', confirm: '' });
  
  // Password Visibility States
  const [showCurrentPass, setShowCurrentPass] = useState(false);
  const [showNewPass, setShowNewPass] = useState(false);
  const [showConfirmPass, setShowConfirmPass] = useState(false);
  const [showEmailPass, setShowEmailPass] = useState(false); // For email update password

  // Email Update States
  const [emailStep, setEmailStep] = useState(1); // 1 = Request, 2 = Verify
  const [emailForm, setEmailForm] = useState({ newEmail: '', password: '', otp: '' });

  useEffect(() => {
    if (isOpen) {
      loadUserData();
      setView('menu');
      setMessage({ type: '', text: '' });
      setEmailStep(1);
      setEmailForm({ newEmail: '', password: '', otp: '' });
      // Reset visibility
      setShowCurrentPass(false);
      setShowNewPass(false);
      setShowConfirmPass(false);
      setShowEmailPass(false);
    }
  }, [isOpen]);

  const loadUserData = async () => {
      try {
          const res = await authAPI.getUser();
          setUser(res.data.data || res.data);
          setNewName(res.data.data?.name || res.data?.name || '');
      } catch (error) {
          const storedName = sessionStorage.getItem('user_name');
          setUser({ name: storedName || 'Admin', email: '...' });
          setNewName(storedName || '');
      }
  };

  const resetMessage = () => setMessage({ type: '', text: '' });

  if (!isOpen) return null;

  // 1. Update Profile Name
  const handleUpdateProfile = async (e) => {
    e.preventDefault();
    setLoading(true);
    resetMessage();
    try {
      await authAPI.updateProfile(newName);
      sessionStorage.setItem('user_name', newName);
      setUser(prev => ({ ...prev, name: newName }));
      setMessage({ type: 'success', text: 'Profile updated successfully!' });
      setTimeout(() => setView('menu'), 1000);
    } catch (error) {
      setMessage({ type: 'error', text: 'Failed to update profile.' });
    } finally {
      setLoading(false);
    }
  };

  // 2. Change Password
  const handleChangePassword = async (e) => {
    e.preventDefault();
    if (passwords.new !== passwords.confirm) {
      setMessage({ type: 'error', text: 'New passwords do not match.' });
      return;
    }
    setLoading(true);
    resetMessage();
    try {
      await authAPI.updatePassword(passwords.current, passwords.new, passwords.confirm);
      
      // --- UPDATED: No Alert, Show Message then Logout ---
      setMessage({ type: 'success', text: 'Password changed successfully. Logging out...' });
      
      setTimeout(async () => {
        await handleLogout(); 
      }, 2000); // Wait 2 seconds so user sees the message
      // -----------------------------------

    } catch (error) {
       const msg = error.response?.data?.message || 'Failed to update password.';
      setMessage({ type: 'error', text: msg });
      setLoading(false);
    }
  };

  // 3. Email Update - Step 1: Send OTP
  const handleInitiateEmailUpdate = async (e) => {
      e.preventDefault();
      setLoading(true);
      resetMessage();
      try {
          await authAPI.initiateEmailUpdate(emailForm.newEmail, emailForm.password);
          setEmailStep(2);
          setMessage({ type: 'success', text: `OTP sent to ${user.email}. Please check your inbox.` });
      } catch (error) {
          const msg = error.response?.data?.message || 'Failed to send OTP. Check credentials.';
          setMessage({ type: 'error', text: msg });
      } finally {
          setLoading(false);
      }
  };

  // 4. Email Update - Step 2: Verify OTP
  const handleCompleteEmailUpdate = async (e) => {
      e.preventDefault();
      setLoading(true);
      resetMessage();
      try {
          await authAPI.completeEmailUpdate(emailForm.otp);
          
          // --- UPDATED: No Alert, Show Message then Logout ---
          setMessage({ type: 'success', text: 'Email updated successfully. Logging out...' });
          
          setTimeout(async () => {
            await handleLogout();
          }, 2000); // Wait 2 seconds so user sees the message
          // -----------------------------------

      } catch (error) {
          const msg = error.response?.data?.message || 'Invalid OTP.';
          setMessage({ type: 'error', text: msg });
          setLoading(false);
      }
  };

  const handleLogout = async () => {
    setLoading(true);
    try {
      await authAPI.logout();
      onLogout(); // This triggers the nice logout modal in Navigation
      onClose();
    } catch (error) {
      console.error("Logout failed", error);
      onLogout();
      onClose();
    } finally {
      setLoading(false);
    }
  };

  const MessageDisplay = () => (
    message.text && (
      <div className={`mb-4 p-3 rounded-lg text-sm font-raleway flex items-center gap-2 ${message.type === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'}`}>
        {message.type === 'success' && <ShieldCheck size={16} />}
        {message.text}
      </div>
    )
  );

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center bg-[#28364b]/60 backdrop-blur-sm animate-fade-in">
      <div className="bg-white rounded-xl shadow-2xl w-full max-w-md p-0 relative mx-4 border border-[#cebd88] overflow-hidden">
        
        {/* Header */}
        <div className="bg-[#28364b] p-6 text-white relative">
            <button onClick={onClose} className="absolute top-4 right-4 text-gray-300 hover:text-white">
                <X size={20} />
            </button>
            <div className="flex items-center gap-4">
                <div className="bg-[#cebd88] p-3 rounded-full text-[#28364b]">
                    <User size={24} />
                </div>
                <div>
                    <h2 className="text-xl font-bold font-playfair">{user.name}</h2>
                    <p className="text-xs text-gray-300 font-raleway tracking-wider truncate max-w-[200px]">{user.email}</p>
                </div>
            </div>
        </div>

        <div className="p-6">
            <MessageDisplay />

            {/* VIEW: MENU */}
            {view === 'menu' && (
                <div className="space-y-2">
                    <button onClick={() => setView('edit')} className="w-full flex items-center justify-between p-4 rounded-lg hover:bg-gray-50 group transition-colors border border-transparent hover:border-gray-200">
                        <div className="flex items-center gap-3 text-[#28364b]">
                            <User size={18} />
                            <span className="font-raleway font-semibold">Edit Name</span>
                        </div>
                        <ChevronRight size={16} className="text-gray-400 group-hover:text-[#cebd88]" />
                    </button>
                    
                    <button onClick={() => setView('email')} className="w-full flex items-center justify-between p-4 rounded-lg hover:bg-gray-50 group transition-colors border border-transparent hover:border-gray-200">
                        <div className="flex items-center gap-3 text-[#28364b]">
                            <Mail size={18} />
                            <span className="font-raleway font-semibold">Change Email</span>
                        </div>
                        <ChevronRight size={16} className="text-gray-400 group-hover:text-[#cebd88]" />
                    </button>

                    <button onClick={() => setView('password')} className="w-full flex items-center justify-between p-4 rounded-lg hover:bg-gray-50 group transition-colors border border-transparent hover:border-gray-200">
                        <div className="flex items-center gap-3 text-[#28364b]">
                            <ShieldCheck size={18} />
                            <span className="font-raleway font-semibold">Change Password</span>
                        </div>
                        <ChevronRight size={16} className="text-gray-400 group-hover:text-[#cebd88]" />
                    </button>

                    <hr className="my-2 border-gray-100" />

                    <button onClick={handleLogout} className="w-full flex items-center gap-3 p-4 rounded-lg text-red-600 hover:bg-red-50 transition-colors font-raleway font-semibold">
                        {loading ? <Loader2 size={18} className="animate-spin" /> : <LogOut size={18} />}
                        <span>Sign Out</span>
                    </button>
                </div>
            )}

            {/* VIEW: EDIT PROFILE */}
            {view === 'edit' && (
                <form onSubmit={handleUpdateProfile} className="space-y-4">
                    <div className="flex items-center gap-2 mb-4 text-[#28364b] cursor-pointer" onClick={() => setView('menu')}>
                        <ArrowLeft size={16} /> <span className="text-sm font-bold font-raleway">Back</span>
                    </div>
                    <div>
                        <label className="text-xs font-bold text-[#28364b] uppercase tracking-wider">Full Name</label>
                        <input 
                            type="text" 
                            value={newName}
                            onChange={(e) => setNewName(e.target.value)}
                            className="w-full mt-1 px-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-[#cebd88] focus:ring-1 focus:ring-[#cebd88] font-raleway"
                        />
                    </div>
                    <button type="submit" disabled={loading} className="w-full bg-[#28364b] text-white py-2 rounded-lg font-raleway font-bold hover:bg-[#3c4a63] transition-colors flex justify-center items-center gap-2">
                        {loading ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />} Save Changes
                    </button>
                </form>
            )}

            {/* VIEW: CHANGE EMAIL */}
            {view === 'email' && (
                <div className="space-y-4">
                    <div className="flex items-center gap-2 mb-4 text-[#28364b] cursor-pointer" onClick={() => { setView('menu'); setEmailStep(1); }}>
                        <ArrowLeft size={16} /> <span className="text-sm font-bold font-raleway">Back</span>
                    </div>
                    
                    {emailStep === 1 ? (
                        <form onSubmit={handleInitiateEmailUpdate} className="space-y-4">
                            <p className="text-xs text-gray-500 font-raleway mb-2">We will send an OTP to your <b>current email</b> ({user.email}) to verify this request.</p>
                            <div>
                                <label className="text-xs font-bold text-[#28364b] uppercase tracking-wider">New Email Address</label>
                                <input 
                                    type="email" 
                                    value={emailForm.newEmail}
                                    onChange={(e) => setEmailForm({...emailForm, newEmail: e.target.value})}
                                    className="w-full mt-1 px-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-[#cebd88] focus:ring-1 focus:ring-[#cebd88] font-raleway"
                                    placeholder="new.email@example.com"
                                    required
                                />
                            </div>
                            <div className="relative">
                                <label className="text-xs font-bold text-[#28364b] uppercase tracking-wider">Current Password</label>
                                <input 
                                    type={showEmailPass ? "text" : "password"}
                                    value={emailForm.password}
                                    onChange={(e) => setEmailForm({...emailForm, password: e.target.value})}
                                    className="w-full mt-1 px-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-[#cebd88] focus:ring-1 focus:ring-[#cebd88] font-raleway pr-10"
                                    placeholder="Confirm identity"
                                    required
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowEmailPass(!showEmailPass)}
                                    className="absolute right-3 top-8 text-gray-400 hover:text-[#28364b]"
                                >
                                    {showEmailPass ? <EyeOff size={16} /> : <Eye size={16} />}
                                </button>
                            </div>
                            <button type="submit" disabled={loading} className="w-full bg-[#28364b] text-white py-2 rounded-lg font-raleway font-bold hover:bg-[#3c4a63] transition-colors flex justify-center items-center gap-2">
                                {loading ? <Loader2 size={16} className="animate-spin" /> : <Mail size={16} />} Send OTP
                            </button>
                        </form>
                    ) : (
                        <form onSubmit={handleCompleteEmailUpdate} className="space-y-4 animate-fade-in">
                            <div className="bg-yellow-50 border border-yellow-200 p-3 rounded text-xs text-yellow-800 font-raleway mb-2">
                                An OTP has been sent to <b>{user.email}</b>. It expires in 10 minutes.
                            </div>
                            <div>
                                <label className="text-xs font-bold text-[#28364b] uppercase tracking-wider">Enter 6-Digit OTP</label>
                                <input 
                                    type="text" 
                                    maxLength="6"
                                    value={emailForm.otp}
                                    onChange={(e) => setEmailForm({...emailForm, otp: e.target.value.replace(/\D/g, '')})}
                                    className="w-full mt-1 px-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-[#cebd88] focus:ring-1 focus:ring-[#cebd88] font-raleway text-center text-lg tracking-widest"
                                    placeholder="000000"
                                    required
                                />
                            </div>
                            <button type="submit" disabled={loading} className="w-full bg-[#cebd88] text-[#28364b] py-2 rounded-lg font-raleway font-bold hover:bg-[#b5a472] transition-colors flex justify-center items-center gap-2">
                                {loading ? <Loader2 size={16} className="animate-spin" /> : <KeyRound size={16} />} Verify & Update Email
                            </button>
                        </form>
                    )}
                </div>
            )}

            {/* VIEW: CHANGE PASSWORD */}
            {view === 'password' && (
                <form onSubmit={handleChangePassword} className="space-y-4">
                     <div className="flex items-center gap-2 mb-4 text-[#28364b] cursor-pointer" onClick={() => setView('menu')}>
                        <ArrowLeft size={16} /> <span className="text-sm font-bold font-raleway">Back</span>
                    </div>
                    
                    {/* Current Password */}
                    <div className="relative">
                        <label className="text-xs font-bold text-[#28364b] uppercase tracking-wider">Current Password</label>
                        <input 
                            type={showCurrentPass ? "text" : "password"} 
                            value={passwords.current}
                            onChange={(e) => setPasswords({...passwords, current: e.target.value})}
                            className="w-full mt-1 px-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-[#cebd88] focus:ring-1 focus:ring-[#cebd88] font-raleway pr-10"
                        />
                         <button
                            type="button"
                            onClick={() => setShowCurrentPass(!showCurrentPass)}
                            className="absolute right-3 top-8 text-gray-400 hover:text-[#28364b]"
                        >
                            {showCurrentPass ? <EyeOff size={16} /> : <Eye size={16} />}
                        </button>
                    </div>

                    {/* New Password */}
                    <div className="relative">
                        <label className="text-xs font-bold text-[#28364b] uppercase tracking-wider">New Password</label>
                        <input 
                            type={showNewPass ? "text" : "password"} 
                            value={passwords.new}
                            onChange={(e) => setPasswords({...passwords, new: e.target.value})}
                            className="w-full mt-1 px-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-[#cebd88] focus:ring-1 focus:ring-[#cebd88] font-raleway pr-10"
                        />
                        <button
                            type="button"
                            onClick={() => setShowNewPass(!showNewPass)}
                            className="absolute right-3 top-8 text-gray-400 hover:text-[#28364b]"
                        >
                            {showNewPass ? <EyeOff size={16} /> : <Eye size={16} />}
                        </button>
                    </div>

                    {/* Confirm Password */}
                    <div className="relative">
                        <label className="text-xs font-bold text-[#28364b] uppercase tracking-wider">Confirm New Password</label>
                        <input 
                            type={showConfirmPass ? "text" : "password"} 
                            value={passwords.confirm}
                            onChange={(e) => setPasswords({...passwords, confirm: e.target.value})}
                            className="w-full mt-1 px-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-[#cebd88] focus:ring-1 focus:ring-[#cebd88] font-raleway pr-10"
                        />
                        <button
                            type="button"
                            onClick={() => setShowConfirmPass(!showConfirmPass)}
                            className="absolute right-3 top-8 text-gray-400 hover:text-[#28364b]"
                        >
                            {showConfirmPass ? <EyeOff size={16} /> : <Eye size={16} />}
                        </button>
                    </div>

                    <button type="submit" disabled={loading} className="w-full bg-[#28364b] text-white py-2 rounded-lg font-raleway font-bold hover:bg-[#3c4a63] transition-colors flex justify-center items-center gap-2">
                        {loading ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />} Update Password
                    </button>
                </form>
            )}

        </div>
      </div>
    </div>
  );
}