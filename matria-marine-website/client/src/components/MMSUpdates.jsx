import React, { useEffect, useState } from 'react';
import { Calendar, Plus, Edit2, Trash2, X, Loader2, AlertTriangle, CheckCircle, ImageIcon, UploadCloud } from 'lucide-react';
import { updatesAPI } from '../pages/api'; 

export default function MMSUpdates() {
  const [updates, setUpdates] = useState([]);
  const [isAdmin, setIsAdmin] = useState(false);
  const [loading, setLoading] = useState(true);

  // --- Modals State ---
  const [isFormModalOpen, setIsFormModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [showSuccessModal, setShowSuccessModal] = useState(false);
  const [successMessage, setSuccessMessage] = useState('');

  // --- Form Data State ---
  const [editingId, setEditingId] = useState(null); 
  const [formData, setFormData] = useState({ title: '', description: '', date_posted: '', image: null });
  const [previewUrl, setPreviewUrl] = useState(null);
  
  // --- Processing States ---
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [deletingId, setDeletingId] = useState(null);

  // 1. Initial Fetch
  useEffect(() => {
    window.scrollTo(0, 0);
    const user = sessionStorage.getItem('user_name');
    setIsAdmin(!!user);
    fetchUpdates();
  }, []);

  const fetchUpdates = async () => {
    try {
      const res = await updatesAPI.getAll();
      if (res.data.success) {
        // --- UPDATED SORTING LOGIC ---
        // 1. Sort by Date Posted (Newest First)
        // 2. Tie-breaker: Sort by ID (Newest Upload First)
        const sortedData = res.data.data.sort((a, b) => {
            const dateA = new Date(a.date_posted);
            const dateB = new Date(b.date_posted);
            
            // If dates are different, sort by date
            if (dateA > dateB) return -1;
            if (dateA < dateB) return 1;
            
            // If dates are the same, sort by ID (newest ID first)
            return b.id - a.id;
        });
        
        setUpdates(sortedData);
      }
    } catch (err) {
      console.error("Failed to fetch updates", err);
    } finally {
      setLoading(false);
    }
  };

  // --- Handlers: Form ---
  const openCreateModal = () => {
    setEditingId(null);
    setFormData({ title: '', description: '', date_posted: new Date().toISOString().split('T')[0], image: null });
    setPreviewUrl(null);
    setIsFormModalOpen(true);
  };

  const openEditModal = (item) => {
    setEditingId(item.id);
    setFormData({ 
        title: item.title, 
        description: item.description, 
        date_posted: item.date_posted, 
        image: null 
    });
    setPreviewUrl(item.image_path); 
    setIsFormModalOpen(true);
  };

  const handleFileChange = (e) => {
      const file = e.target.files[0];
      if (file) {
          setFormData({ ...formData, image: file });
          setPreviewUrl(URL.createObjectURL(file));
      }
  };

  const handleSubmit = async (e) => {
      e.preventDefault();
      setIsSubmitting(true);

      const data = new FormData();
      data.append('title', formData.title);
      data.append('description', formData.description);
      data.append('date_posted', formData.date_posted);
      // Only append image if a new one was selected
      if (formData.image) {
          data.append('image', formData.image);
      }

      try {
          let res;
          if (editingId) {
              res = await updatesAPI.update(editingId, data);
          } else {
              res = await updatesAPI.create(data);
          }

          if (res.data.success) {
              setSuccessMessage(editingId ? 'Update updated successfully!' : 'New update posted!');
              setShowSuccessModal(true);
              setIsFormModalOpen(false);
              fetchUpdates();
              setTimeout(() => setShowSuccessModal(false), 3000);
          }
      } catch (err) {
          alert(`Error: ${err.response?.data?.message || err.message}`);
      } finally {
          setIsSubmitting(false);
      }
  };

  // --- Handlers: Delete ---
  const confirmDelete = (id) => {
      setDeletingId(id);
      setIsDeleteModalOpen(true);
  };

  const executeDelete = async () => {
      if (!deletingId) return;
      setIsSubmitting(true);
      try {
          const res = await updatesAPI.delete(deletingId);
          if (res.data.success) {
              setSuccessMessage('Update deleted successfully.');
              setShowSuccessModal(true);
              setIsDeleteModalOpen(false);
              fetchUpdates();
              setTimeout(() => setShowSuccessModal(false), 3000);
          }
      } catch (err) {
          alert("Delete failed");
      } finally {
          setIsSubmitting(false);
          setDeletingId(null);
      }
  };

  return (
    <>
      <style>
        {`
          @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Raleway:wght@400;600;700&display=swap');
          .font-playfair { font-family: 'Playfair Display', serif; }
          .font-raleway { font-family: 'Raleway', sans-serif; }
          .animate-fade-in-up { animation: fadeInUp 0.5s ease-out; }
          @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        `}
      </style>

      {/* --- Success Modal --- */}
      {showSuccessModal && (
        <div className="fixed inset-0 z-[70] flex items-end sm:items-start justify-center px-4 py-6 pointer-events-none sm:p-6">
            <div className="max-w-sm w-full bg-white shadow-lg rounded-lg pointer-events-auto ring-1 ring-black ring-opacity-5 overflow-hidden animate-fade-in-up">
              <div className="p-4 flex">
                  <CheckCircle className="h-6 w-6 text-green-400" />
                  <div className="ml-3 w-0 flex-1 pt-0.5">
                    <p className="text-sm font-medium text-gray-900">Success!</p>
                    <p className="mt-1 text-sm text-gray-500">{successMessage}</p>
                  </div>
              </div>
            </div>
        </div>
      )}

      {/* --- Delete Confirmation Modal --- */}
      {isDeleteModalOpen && (
          <div className="fixed inset-0 z-[65] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
             <div className="bg-white rounded-lg p-6 max-w-sm w-full animate-fade-in-up">
                <div className="flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mx-auto mb-4">
                    <AlertTriangle className="h-6 w-6 text-red-600" />
                </div>
                <h3 className="text-lg font-medium text-center mb-2">Delete Update?</h3>
                <p className="text-sm text-gray-500 text-center mb-6">This action cannot be undone.</p>
                <div className="flex gap-3">
                  <button onClick={() => setIsDeleteModalOpen(false)} disabled={isSubmitting} className="flex-1 py-2 border rounded-lg hover:bg-gray-50">Cancel</button>
                  <button onClick={executeDelete} disabled={isSubmitting} className="flex-1 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 flex justify-center items-center">
                    {isSubmitting ? <Loader2 className="animate-spin h-4 w-4"/> : 'Delete'}
                  </button>
                </div>
             </div>
          </div>
      )}

      {/* --- REVISED: Add/Edit Form Modal --- */}
      {isFormModalOpen && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/70 backdrop-blur-md p-4">
          <div className="bg-white rounded-xl shadow-2xl w-full max-w-2xl overflow-hidden animate-fade-in-up flex flex-col max-h-[90vh]">
            
            {/* Modal Header */}
            <div className="px-8 py-5 border-b border-gray-200 flex justify-between items-center bg-gray-50">
              <div>
                <h3 className="font-playfair font-bold text-2xl text-[#28364b]">
                    {editingId ? 'Edit Update' : 'Post New Update'}
                </h3>
                <p className="text-xs text-gray-500 mt-1 uppercase tracking-wider">MMS Management Console</p>
              </div>
              <button onClick={() => setIsFormModalOpen(false)} className="text-gray-400 hover:text-red-500 transition-colors p-2 rounded-full hover:bg-red-50">
                <X size={24}/>
              </button>
            </div>
            
            {/* Modal Body (Scrollable) */}
            <div className="overflow-y-auto p-8">
                <form onSubmit={handleSubmit} className="space-y-6">
                    
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {/* Title Input */}
                        <div className="col-span-1 md:col-span-2">
                            <label className="block text-xs font-bold text-[#28364b] uppercase tracking-wide mb-2">Headline Title</label>
                            <input 
                                className="w-full border-2 border-gray-300 rounded-lg p-3 text-gray-700 font-bold focus:border-[#cebd88] focus:ring-0 outline-none transition-all bg-white" 
                                value={formData.title} 
                                onChange={e => setFormData({...formData, title: e.target.value})} 
                                placeholder="Enter a catchy title..."
                                required 
                            />
                        </div>

                        {/* Date Input */}
                        <div>
                            <label className="block text-xs font-bold text-[#28364b] uppercase tracking-wide mb-2">Date Posted</label>
                            <input 
                                type="date"
                                className="w-full border-2 border-gray-300 rounded-lg p-3 text-gray-700 focus:border-[#cebd88] focus:ring-0 outline-none transition-all bg-white" 
                                value={formData.date_posted} 
                                onChange={e => setFormData({...formData, date_posted: e.target.value})} 
                                required 
                            />
                        </div>
                    </div>

                    {/* Description Input */}
                    <div>
                        <label className="block text-xs font-bold text-[#28364b] uppercase tracking-wide mb-2">Content Body</label>
                        <textarea 
                            className="w-full border-2 border-gray-300 rounded-lg p-3 text-gray-700 leading-relaxed focus:border-[#cebd88] focus:ring-0 outline-none transition-all bg-white" 
                            rows="6"
                            value={formData.description} 
                            onChange={e => setFormData({...formData, description: e.target.value})} 
                            placeholder="Write the details of the update here..."
                            required 
                        />
                    </div>
                    
                    {/* Image Upload Area */}
                    <div>
                        <label className="block text-xs font-bold text-[#28364b] uppercase tracking-wide mb-2">Featured Image</label>
                        
                        <div className="flex gap-6 items-start">
                            {/* Preview Box */}
                            <div className="w-32 h-32 flex-shrink-0 bg-gray-100 rounded-lg border-2 border-gray-200 overflow-hidden relative">
                                {previewUrl ? (
                                    <img src={previewUrl} className="w-full h-full object-cover" alt="Preview" />
                                ) : (
                                    <div className="w-full h-full flex items-center justify-center text-gray-300">
                                        <ImageIcon size={32} />
                                    </div>
                                )}
                            </div>

                            {/* Upload Control */}
                            <div className="flex-grow">
                                <label className={`flex flex-col items-center justify-center w-full h-32 border-2 border-dashed rounded-lg cursor-pointer transition-colors ${previewUrl ? 'border-[#cebd88] bg-[#cebd88]/5' : 'border-gray-300 hover:bg-gray-50'}`}>
                                    <div className="flex flex-col items-center justify-center pt-5 pb-6">
                                        <UploadCloud className={`w-8 h-8 mb-3 ${previewUrl ? 'text-[#cebd88]' : 'text-gray-400'}`} />
                                        <p className="mb-2 text-sm text-gray-500"><span className="font-semibold">Click to upload</span></p>
                                        <p className="text-xs text-gray-400">SVG, PNG, JPG or WEBP</p>
                                    </div>
                                    <input type="file" className="hidden" onChange={handleFileChange} accept="image/*" required={!editingId && !previewUrl} />
                                </label>
                            </div>
                        </div>
                    </div>

                    {/* Action Buttons */}
                    <div className="pt-4 flex items-center gap-4">
                        <button type="button" onClick={() => setIsFormModalOpen(false)} className="flex-1 py-4 border-2 border-gray-200 text-gray-600 font-bold rounded-xl hover:bg-gray-50 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" disabled={isSubmitting} className="flex-[2] bg-[#cebd88] hover:bg-[#b5a36e] text-[#28364b] font-bold py-4 rounded-xl flex justify-center items-center gap-2 transition-all shadow-md">
                            {isSubmitting ? <Loader2 className="animate-spin" /> : (editingId ? 'Save Changes' : 'Publish Update')}
                        </button>
                    </div>

                </form>
            </div>
          </div>
        </div>
      )}


      {/* ================= MAIN CONTENT ================= */}

      <section className="min-h-screen bg-[#F1F0E8] py-16">
        <div className="container mx-auto px-4 md:px-8 lg:px-20">
          
          {/* --- Page Header --- */}
          <div className="text-center mb-12 animate-fade-in-up relative">
            <h1 className="text-3xl md:text-4xl font-bold font-playfair text-[#28364b] mb-4">
              Matria Marine Services Updates
            </h1>
            <p className="font-raleway text-[#28364b] text-base max-w-2xl mx-auto mb-5">
              Stay informed with the latest news, operational advisories, and announcements.
            </p>
            <div className="w-16 h-1 bg-[#cebd88] mx-auto rounded-full"/>

            {/* Admin Add Button */}
            {isAdmin && (
                <button onClick={openCreateModal} className="absolute top-0 right-0 hidden lg:flex items-center gap-2 bg-[#28364b] text-white px-5 py-2.5 rounded-lg hover:bg-[#3c4a63] transition-all shadow-md">
                    <Plus size={18} /> <span>New Update</span>
                </button>
            )}
          </div>

          {/* --- Updates Grid --- */}
          {loading ? (
             <div className="flex justify-center py-20"><Loader2 className="animate-spin text-[#cebd88] w-12 h-12" /></div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {updates.length === 0 && <p className="col-span-full text-center text-gray-500">No updates posted yet.</p>}
                
                {updates.map((item) => (
                <div 
                    key={item.id} 
                    className="bg-white rounded-xl shadow-md overflow-hidden hover:shadow-xl transition-all duration-300 flex flex-col h-full border border-gray-100 group animate-fade-in-up"
                >
                    {/* Image */}
                    <div className="h-48 overflow-hidden relative">
                    <img 
                        src={item.image_path} 
                        alt={item.title} 
                        className="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
                    />
                    
                    {/* Admin Actions Overlay */}
                    {isAdmin && (
                        <div className="absolute top-2 right-2 flex gap-2">
                            <button onClick={() => openEditModal(item)} className="bg-white/90 p-2 rounded-full text-gray-700 hover:text-blue-600 hover:bg-white shadow-sm" title="Edit">
                                <Edit2 size={16} />
                            </button>
                            <button onClick={() => confirmDelete(item.id)} className="bg-white/90 p-2 rounded-full text-gray-700 hover:text-red-600 hover:bg-white shadow-sm" title="Delete">
                                <Trash2 size={16} />
                            </button>
                        </div>
                    )}
                    </div>

                    {/* Content */}
                    <div className="p-6 flex flex-col flex-grow">
                        <h3 className="text-xl font-bold font-playfair text-[#28364b] mb-3 leading-tight group-hover:text-[#cebd88] transition-colors">
                            {item.title}
                        </h3>
                        
                        <p className="font-raleway text-sm text-gray-600 mb-5 flex-grow leading-relaxed text-justify whitespace-pre-wrap">
                            {item.description}
                        </p>

                        <div className="pt-4 border-t border-gray-100 flex text-[#28364b] items-center font-raleway text-xs font-bold">
                            <Calendar size={14} className="mr-2" />
                            {new Date(item.date_posted).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}
                        </div>
                    </div>
                </div>
                ))}
            </div>
          )}
          
        </div>
      </section>
    </>
  );
}